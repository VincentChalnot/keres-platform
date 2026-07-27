<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Game;
use App\Entity\GamePlayer;
use App\Entity\User;
use App\Model\OpponentType;
use App\Model\PieceColor;

final class GameFactory
{
    public function createAiOrHotseatGame(User $creator, OpponentType $opponentType, PieceColor $creatorColor): Game
    {
        $game = new Game($creator, $opponentType);
        new GamePlayer($game, $creatorColor, $creator);

        if (OpponentType::AI === $opponentType) {
            new GamePlayer($game, $creatorColor->opposite(), null);
        } else {
            new GamePlayer($game, $creatorColor->opposite(), $creator);
        }

        return $game;
    }

    public function createMultiplayerGame(User $creator, User $opponent, PieceColor $creatorColor): Game
    {
        $game = new Game($creator, OpponentType::MULTIPLAYER);
        new GamePlayer($game, $creatorColor, $creator);
        new GamePlayer($game, $creatorColor->opposite(), $opponent);

        return $game;
    }
}
