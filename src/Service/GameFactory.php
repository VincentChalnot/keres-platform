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
     */
    public function createMultiplayerGame(
        User $creator,
        User $opponent,
        PieceColor $creatorColor,
        ?TimeControl $timeControl = null,
        bool $rated = false,
    ): Game {
        $game = new Game($creator, OpponentType::MULTIPLAYER, $timeControl ?? TimeControl::unlimited(), $rated);
        new GamePlayer($game, $creatorColor, $creator);
        new GamePlayer($game, $creatorColor->opposite(), $opponent);

        $this->clockManager->arm($game);

        return $game;
    }
}
