<?php

declare(strict_types=1);

namespace App\Engine;

use App\Entity\Game;
use App\Exception\GameAlreadyFinishedException;
use App\Exception\MoveFlaggedException;
use App\Exception\StalePositionException;
use App\Model\BoardMovesData;
use App\Model\MoveData;
use App\Model\PieceColor;
use App\Service\Game\ClockManager;
use App\Service\Game\GameLifecycleManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

readonly class GameEngine
{
    public function __construct(
        private BoardTreeManager $boardTreeManager,
        private EntityManagerInterface $entityManager,
        private EngineApi $engineApi,
        private ClockManager $clockManager,
        private GameLifecycleManager $gameLifecycleManager,
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
        $aiMoveData = $this->engineApi->aiMove($movesData);

        // Apply AI move
        return $this->applyMove($game, $aiMoveData, $receivedAtMicros);
    }
}
