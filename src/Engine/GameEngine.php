<?php

declare(strict_types=1);

namespace App\Engine;

use App\Entity\Game;
use App\Exception\GameAlreadyFinishedException;
use App\Exception\MoveFlaggedException;
use App\Exception\StalePositionException;
use App\Message\CheckClockExpiryMessage;
use App\Model\BoardMovesData;
use App\Model\MoveData;
use App\Model\PieceColor;
use App\Model\TimeControlKind;
use App\Service\Analytics\AnalyticsRecorder;
use App\Service\Game\ClockManager;
use App\Service\Game\GameLifecycleManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

readonly class GameEngine
{
    public function __construct(
        private BoardTreeManager $boardTreeManager,
        private EntityManagerInterface $entityManager,
        private EngineApi $engineApi,
        private ClockManager $clockManager,
        private GameLifecycleManager $gameLifecycleManager,
        private AnalyticsRecorder $analyticsRecorder,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * Returns a BoardMovesData for the current game state (board after all moves played).
     */
    public function getBoardMovesData(Game $game): BoardMovesData
    {
        $movesData = $game->getMovesData();
        $boardData = $this->engineApi->replayMoves($movesData);

        return new BoardMovesData($boardData, $movesData);
    }

    /**
     * @throws GameAlreadyFinishedException post-lock re-check: already over (409 game_finished)
     * @throws StalePositionException post-lock re-check: move count changed under us (409 not_your_turn)
     * @throws MoveFlaggedException the mover's clock had already run out; the game is
     *                              finalised and committed by the time this throws (409 flagged)
     */
    public function applyMove(Game $game, MoveData $moveData, int $receivedAtMicros): BoardMovesData
    {
        // 1. Engine round trip: no transaction, no lock, unbounded, uncharged.
        $movesData = $game->getMovesData();
        $movesData->addMove($moveData);
        $expectedMoveCount = $game->getGameMoves()->count();
        $mover = $game->isWhiteTurn() ? PieceColor::WHITE : PieceColor::BLACK;
        $boardData = $this->engineApi->replayMoves($movesData);
        $boardMovesData = new BoardMovesData($boardData, $movesData);

        // 2. One transaction, one lock, one flush. Returns whether the move
        // was rejected as flagged - thrown *after* this returns, so the
        // flag-finalisation commit is never rolled back by the throw.
        $flagged = $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $em) use ($game, $boardMovesData, $boardData, $expectedMoveCount, $receivedAtMicros, $mover): bool {
                $em->getConnection()->executeStatement("SET LOCAL lock_timeout = '3s'");

                // SELECT ... FOR UPDATE + re-hydrate (EntityManager.php:339-343).
                $em->find(Game::class, $game->getId(), LockMode::PESSIMISTIC_WRITE);

                if (null !== $game->getGameOverAt()) {
                    throw new GameAlreadyFinishedException();
                }

                if ($game->getGameMoves()->count() !== $expectedMoveCount) {
                    throw new StalePositionException();
                }

                $outcome = $this->clockManager->chargeAndSwap($game, $mover, $receivedAtMicros, $this->clockManager->nowMicros());

                if ($outcome->flagged) {
                    $this->clockManager->stop($game, $receivedAtMicros);
                    $this->gameLifecycleManager->finaliseTimeout($game, $mover);
                    $em->flush();

                    return true;
                }

                $newMove = $this->boardTreeManager->getGameMove($game, $boardMovesData);
                $em->persist($newMove);
                $game->setDrawOfferedByColor(null); // any move revokes a standing offer

                if ($boardData->gameOver) {
                    $this->gameLifecycleManager->finaliseEngineResult($game, $boardData->whiteWins, $boardData->draw);
                    $this->clockManager->stop($game, $receivedAtMicros);
                }

                $em->flush();

                return false;
            }
        );

        if ($flagged) {
            throw new MoveFlaggedException();
        }

        // 03-time-control.md sec 4.1 step 21, after commit (a message must not
        // be consumable before the state it checks is visible). Here rather
        // than in each caller so every move path - human, AI reply, bot game -
        // arms its flag check; the deadline sweep is the backstop if this
        // dispatch is lost to a crash.
        $this->armClockExpiryCheck($game);

        // T6: dispatched after the transaction has already committed - a
        // rejected/stale move never reaches here (it throws above or from
        // inside the transaction). Cheap, async (T1): no query, no join, no
        // wait for a flush, even on this per-ply hot path.
        $this->analyticsRecorder->movePlayed($game, $mover);

        return $boardMovesData;
    }

    public function aiMove(Game $game, int $receivedAtMicros): BoardMovesData
    {
        if ($game->isGameOver()) {
            // Game is already over, nothing to do
            throw new \RuntimeException('Game is over.');
        }

        // Get current board state
        $movesData = $game->getMovesData();

        // Get AI move
        $aiMoveData = $this->engineApi->aiMove($movesData, $game->getAiLevel() ?? 1);

        // Apply AI move
        return $this->applyMove($game, $aiMoveData, $receivedAtMicros);
    }

    /**
     * CORRESPONDENCE deadlines (6h-72h out) belong to the sweep command, not
     * a per-move DelayStamp. Everything else with a live deadline is checked
     * at `deadline + grace`; an UNLIMITED game only carries one for its first
     * two plies (the abort clamp). The sweep is the backstop if this is lost.
     */
    private function armClockExpiryCheck(Game $game): void
    {
        $deadline = $game->getMoveDeadlineAt();

        if (null === $deadline || $game->isGameOver() || TimeControlKind::CORRESPONDENCE === $game->getTimeControl()->getKind()) {
            return;
        }

        $this->messageBus->dispatch(
            new CheckClockExpiryMessage($game->getUuid()->toRfc4122(), $game->getGameMoves()->count(), (int) $deadline->format('Uu')),
            [new DelayStamp($this->clockManager->expiryCheckDelayMs($deadline))],
        );
    }
}
