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

/**
 * Engine evaluations must never be reachable during a rated game, whatever
 * the flags say; once any game is over they are the replay's.
 */
final class GameEvaluationExposureTest extends TestCase
{
    public function testUnratedGameThatOptedInExposesTheLiveEvaluation(): void
    {
        $game = $this->makeGame(rated: false, liveEvaluation: true);

        self::assertTrue($game->isLiveEvaluationEnabled());
        self::assertTrue($game->canExposeEvaluation());
    }

    public function testUnratedGameThatDidNotOptInHidesItWhileInProgress(): void
    {
        $game = $this->makeGame(rated: false, liveEvaluation: false);

        self::assertFalse($game->isLiveEvaluationEnabled());
        self::assertFalse($game->canExposeEvaluation());
    }

    public function testRatedGameCanNeverEnableTheLiveEvaluation(): void
    {
        $game = $this->makeGame(rated: true, liveEvaluation: true);

        self::assertFalse($game->isLiveEvaluationEnabled());
        self::assertFalse($game->canExposeEvaluation());
    }

    public function testAnyFinishedGameExposesEvaluationsForTheReplay(): void
    {
        $game = $this->makeGame(rated: true, liveEvaluation: false);
        $game->finish(GameEndReason::RESIGNATION, PieceColor::WHITE);

        self::assertTrue($game->canExposeEvaluation());
    }

    private function makeGame(bool $rated, bool $liveEvaluation): Game
    {
        $game = new Game(new User('creator@example.com'), OpponentType::MULTIPLAYER, TimeControl::realtime(300, 0), $rated, null, $liveEvaluation);
        new GamePlayer($game, PieceColor::WHITE, new User('white@example.com'));
        new GamePlayer($game, PieceColor::BLACK, new User('black@example.com'));

        return $game;
    }
}
