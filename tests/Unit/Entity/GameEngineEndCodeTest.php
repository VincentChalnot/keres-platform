<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Game;
use App\Entity\GamePlayer;
use App\Entity\User;
use App\Model\GameEndReason;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Model\TimeControl;
use PHPUnit\Framework\TestCase;

final class GameEngineEndCodeTest extends TestCase
{
    public function testEngineEndingKeepsTheEnginesCode(): void
    {
        $game = $this->makeGame();
        $game->finish(GameEndReason::ENGINE, null, 2);

        self::assertSame(2, $game->getEngineEndCode());
    }

    public function testNonEngineEndingNeverCarriesACode(): void
    {
        $game = $this->makeGame();
        $game->finish(GameEndReason::RESIGNATION, PieceColor::WHITE, 1);

        self::assertNull($game->getEngineEndCode());
    }

    public function testUndoClearsTheCode(): void
    {
        $game = $this->makeGame();
        $game->finish(GameEndReason::ENGINE, PieceColor::BLACK, 1);
        $game->reopenForUndo();

        self::assertNull($game->getEngineEndCode());
    }

    public function testBackfillWritesOnceForAnEngineEndedGame(): void
    {
        $game = $this->makeGame();
        $game->finish(GameEndReason::ENGINE, PieceColor::WHITE);
        $game->setEngineEndCode(1);

        self::assertSame(1, $game->getEngineEndCode());

        $this->expectException(\LogicException::class);
        $game->setEngineEndCode(3);
    }

    public function testBackfillRefusesANonEngineGame(): void
    {
        $game = $this->makeGame();
        $game->finish(GameEndReason::TIMEOUT, PieceColor::WHITE);

        $this->expectException(\LogicException::class);
        $game->setEngineEndCode(1);
    }

    private function makeGame(): Game
    {
        $game = new Game(new User('creator@example.com'), OpponentType::MULTIPLAYER, TimeControl::unlimited(), false);
        new GamePlayer($game, PieceColor::WHITE, new User('white@example.com'));
        new GamePlayer($game, PieceColor::BLACK, new User('black@example.com'));

        return $game;
    }
}
