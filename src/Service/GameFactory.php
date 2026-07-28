<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Game;
use App\Entity\GamePlayer;
use App\Entity\User;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Model\TimeControl;
use App\Service\Game\ClockManager;

final class GameFactory
{
    public function __construct(
        private readonly ClockManager $clockManager,
    ) {
    }

    /** AI/hot-seat games are always casual (D1/D3): unlimited, unrated. */
    public function createAiOrHotseatGame(User $creator, OpponentType $opponentType, PieceColor $creatorColor): Game
    {
        $game = new Game($creator, $opponentType, TimeControl::unlimited(), false);
        new GamePlayer($game, $creatorColor, $creator);

        if (OpponentType::AI === $opponentType) {
            new GamePlayer($game, $creatorColor->opposite(), null);
        } else {
            new GamePlayer($game, $creatorColor->opposite(), $creator);
        }

        $this->clockManager->arm($game);

        return $game;
    }

    /**
     * Until Phase 3's seek/challenge flow lands, `$timeControl`/`$rated`
     * default to unlimited/unrated so a manually-created multiplayer game
     * (there is no matchmaking UI yet) behaves like today's casual games.
     *
     * `$arm = false` leaves the clock columns null (no first-move clamp
     * ticking yet) - `CreateTestGameCommand` uses this to let a human open
     * both browser tabs before starting the 30s "did anyone turn up" timer.
     * Call `arm()` once ready. Never expose `$arm` outside dev/test tooling
     * - every real caller (Phase 3 matchmaking) arms immediately.
     */
    public function createMultiplayerGame(
        User $creator,
        User $opponent,
        PieceColor $creatorColor,
        ?TimeControl $timeControl = null,
        bool $rated = false,
        bool $arm = true,
    ): Game {
        $game = new Game($creator, OpponentType::MULTIPLAYER, $timeControl ?? TimeControl::unlimited(), $rated);
        new GamePlayer($game, $creatorColor, $creator);
        new GamePlayer($game, $creatorColor->opposite(), $opponent);

        if ($arm) {
            $this->clockManager->arm($game);
        }

        return $game;
    }

    /** Passthrough so `ClockManager::arm()` is still only ever reached through GameFactory. */
    public function arm(Game $game): void
    {
        $this->clockManager->arm($game);
    }
}
