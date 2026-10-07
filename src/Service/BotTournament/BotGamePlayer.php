<?php

declare(strict_types=1);

namespace App\Service\BotTournament;

use App\Engine\EngineApi;
use App\Engine\GameEngine;
use App\Entity\Game;
use App\Entity\User;
use App\Exception\MoveFlaggedException;
use App\Model\PieceColor;
use App\Model\TimeControl;
use App\Service\Game\ClockManager;
use App\Service\Game\GameLifecycleManager;
use App\Service\GameFactory;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Plays one rated bot-vs-bot game to completion. The game is a regular
 * MULTIPLAYER game, so it flows through the same GameEngine/RatingUpdater
 * path as human games and feeds the shared board tree + the Glicko-2 pools.
 */
class BotGamePlayer
{
    public function __construct(
        private readonly BotAccounts $botAccounts,
        private readonly GameFactory $gameFactory,
        private readonly GameEngine $gameEngine,
        private readonly EngineApi $engineApi,
        private readonly ClockManager $clockManager,
        private readonly GameLifecycleManager $gameLifecycleManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * A game that cannot be completed (engine failure, `$maxPlies` exceeded)
     * is aborted, so it is neither rated nor left "ongoing" forever, and the
     * original error is rethrown.
     */
    public function play(int $whiteLevel, int $blackLevel, TimeControl $timeControl, int $maxPlies): Game
    {
        $ids = $this->botAccounts->allIds();
        $white = isset($ids[$whiteLevel]) ? $this->entityManager->find(User::class, $ids[$whiteLevel]) : null;
        $black = isset($ids[$blackLevel]) ? $this->entityManager->find(User::class, $ids[$blackLevel]) : null;

        if (null === $white || null === $black) {
            throw new \RuntimeException(\sprintf('Bot accounts for levels %d/%d do not exist, run app:bot-tournament first.', $whiteLevel, $blackLevel));
        }

        $game = $this->gameFactory->createMultiplayerGame($white, $black, PieceColor::WHITE, $timeControl, true);
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        try {
            while (!$game->isGameOver()) {
                if ($game->getGameMoves()->count() >= $maxPlies) {
                    throw new \RuntimeException(\sprintf('Game exceeded %d plies.', $maxPlies));
                }

                $move = $this->engineApi->aiMove($game->getMovesData(), $game->isWhiteTurn() ? $whiteLevel : $blackLevel);

                try {
                    // Stamped after the engine answered: a bot's thinking time
                    // is charged like anyone else's (the increment keeps it sane).
                    $this->gameEngine->applyMove($game, $move, $this->clockManager->nowMicros());
                } catch (MoveFlaggedException) {
                    break; // finalised as a timeout (a real, rated result)
                }
            }
        } catch (\Throwable $e) {
            $this->abortQuietly($game);

            throw $e;
        }

        return $game;
    }

    /** Best effort: never let cleanup mask the original error. */
    private function abortQuietly(Game $game): void
    {
        try {
            if (!$this->entityManager->isOpen()) {
                return;
            }

            $uuid = $game->getUuid()->toRfc4122();
            $this->entityManager->clear();
            $fresh = $this->entityManager->getRepository(Game::class)->findOneBy(['uuid' => $uuid]);

            if ($fresh instanceof Game && !$fresh->isGameOver()) {
                $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($fresh): void {
                    $em->find(Game::class, $fresh->getId(), LockMode::PESSIMISTIC_WRITE);

                    if ($fresh->isGameOver()) {
                        return;
                    }

                    $this->clockManager->stop($fresh, $this->clockManager->nowMicros());
                    $this->gameLifecycleManager->finaliseAbort($fresh);
                    $em->flush();
                });
            }
        } catch (\Throwable) {
        }
    }
}
