<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Engine\GameEngine;
use App\Entity\Game;
use App\Model\MultiplayerLimits;
use App\Model\PieceColor;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Idempotent (invariant 7): two concurrent invocations produce one result,
 * the loser returns false. Never throws for an ordinary state - finished,
 * unarmed, UNLIMITED past its first two plies, or deadline-in-the-future all
 * return false without writing. Publishes exactly one payload when it
 * returns true, after its own commit, never when it returns false
 * (invariant 8). Read-only in the common case: it short-circuits before
 * opening a transaction (03-time-control.md sec 5.1).
 */
final readonly class ClockAdjudicator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GameEngine $gameEngine,
        private ClockManager $clockManager,
        private GameLifecycleManager $gameLifecycleManager,
        private GameStatePayloadBuilder $payloadBuilder,
        private GameUpdatePublisher $publisher,
    ) {
    }

    /** True iff THIS call transitioned the game to finished. */
    public function adjudicate(Game $game): bool
    {
        if (null !== $game->getGameOverAt()) {
            return false;
        }

        $deadline = $game->getMoveDeadlineAt();

        if (null === $deadline) {
            return false;
        }

        $graceMicros = (MultiplayerLimits::CLOCK_LAG_COMPENSATION_MS + MultiplayerLimits::CLOCK_EXPIRY_GRACE_MS) * 1000;
        $deadlineMicros = (int) $deadline->format('Uu');

        if ($this->clockManager->nowMicros() < $deadlineMicros + $graceMicros) {
            return false;
        }

        $plies = $game->getGameMoves()->count();

        $transitioned = $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $em) use ($game, $plies, $graceMicros): bool {
                $em->getConnection()->executeStatement("SET LOCAL lock_timeout = '3s'");

                // SELECT ... FOR UPDATE + re-hydrate (the mutex, sec 5.5).
                $em->find(Game::class, $game->getId(), LockMode::PESSIMISTIC_WRITE);

                $freshDeadline = $game->getMoveDeadlineAt();

                if (null !== $game->getGameOverAt()
                    || null === $freshDeadline
                    || $game->getGameMoves()->count() !== $plies
                    || $this->clockManager->nowMicros() < (int) $freshDeadline->format('Uu') + $graceMicros
                ) {
                    // Someone moved, re-armed, or resolved this first.
                    return false;
                }

                $loser = $game->isWhiteTurn() ? PieceColor::WHITE : PieceColor::BLACK;
                // stop() to moveDeadlineAt, not now(): the recorded outcome
                // is independent of discovery time (sec 5.1).
                $this->clockManager->stop($game, (int) $freshDeadline->format('Uu'));

                if ($plies < 2) {
                    $this->gameLifecycleManager->finaliseAbort($game);
                } else {
                    $this->gameLifecycleManager->finaliseTimeout($game, $loser);
                }

                $em->flush();

                return true;
            }
        );

        if (!$transitioned) {
            return false;
        }

        $boardMovesData = $this->gameEngine->getBoardMovesData($game);
        $payload = $this->payloadBuilder->build($game, $boardMovesData);
        $this->publisher->publishGameState($game->getUuid()->toRfc4122(), $this->payloadBuilder->encode($payload));

        return true;
    }
}
