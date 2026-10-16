<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Entity\User;
use App\Repository\GameRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "One bot game at a time" (D5 of `docs/onboarding-plan.md`): a bot game is a `Game` with a non-null `aiLevel`, whether
 * it is a casual `OpponentType::AI` game or a rated, clocked game against a bot account. The AI backend is billed per
 * call, so a player keeps a single running bot game; hot-seat and human multiplayer games are not concerned.
 *
 * The server is the source of truth: `NewLocalGameAction` refuses to create a second one. This service also ends the
 * current one (`retire()`: resignation, or an abort while it would not count anyway; `abort()`: always an abort, for
 * the clean-up of old backlogs). Neither publishes the finished state: another tab still showing the game learns it
 * is over from the next move it submits (409 `game_finished`, which carries the state).
 */
final readonly class BotGameManager
{
    public function __construct(
        private GameRepository $gameRepository,
        private EntityManagerInterface $entityManager,
        private ClockManager $clockManager,
        private GameLifecycleManager $gameLifecycleManager,
    ) {
    }

    public function findCurrent(User $user): ?Game
    {
        return $this->gameRepository->findInProgressBotGameForUser($user);
    }

    /**
     * Ends the running bot game so that a new one can start: aborted (no result, no rating, no ladder effect) while
     * `Game::isAbortable()`, resigned otherwise.
     */
    public function retire(Game $game, User $user): void
    {
        $this->finish($game, function () use ($game, $user): void {
            if ($game->isAbortable()) {
                $this->gameLifecycleManager->finaliseAbort($game);

                return;
            }

            $colors = $game->getColorsForUser($user);
            $this->gameLifecycleManager->resign($game, 1 === \count($colors) ? $colors[0] : $game->getCreatorColor());
        });
    }

    /** No result whatever the progress of the game: no rating, no ladder effect. */
    public function abort(Game $game): void
    {
        $this->finish($game, fn () => $this->gameLifecycleManager->finaliseAbort($game));
    }

    /** Same transaction and row lock as `ResignGameAction` / `AbortGameAction`. */
    private function finish(Game $game, callable $finalise): void
    {
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($game, $finalise): void {
            $em->getConnection()->executeStatement("SET LOCAL lock_timeout = '3s'");
            $em->find(Game::class, $game->getId(), LockMode::PESSIMISTIC_WRITE);

            if ($game->isGameOver()) {
                return;
            }

            $this->clockManager->stop($game, $this->clockManager->nowMicros());
            $finalise();
            $em->flush();
        });
    }
}
