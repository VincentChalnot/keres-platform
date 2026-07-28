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
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

/**
 * F11 (06-rating.md sec 9.4/6.1): one case per `isRatedOutcome()` clause,
 * each failing in isolation, plus the all-clauses-hold positive case.
 * `Game::isRatedOutcome()` is pure (only its own already-loaded
 * `players`/`gameMoves` collections), so this needs no container/database -
 * `gameMoves` is set via reflection with placeholder elements because only
 * `count()` is ever read off it, sparing the test a full
 * Move/BoardPosition object graph.
 */
final class GameIsRatedOutcomeTest extends TestCase
{
    public function testAllClausesHoldingIsRated(): void
    {
        $game = $this->makeGame(rated: true, timeControl: TimeControl::realtime(300, 0), plyCount: 4);
        $game->finish(GameEndReason::ENGINE, PieceColor::WHITE);

        self::assertTrue($game->isRatedOutcome());
    }

    public function testClause1UnratedConsentFails(): void
    {
        $game = $this->makeGame(rated: false, timeControl: TimeControl::realtime(300, 0), plyCount: 4);
        $game->finish(GameEndReason::ENGINE, PieceColor::WHITE);

        self::assertFalse($game->isRatedOutcome());
    }

    public function testClause2UnlimitedHasNoPool(): void
    {
        $game = $this->makeGame(rated: true, timeControl: TimeControl::unlimited(), plyCount: 4);
        $game->finish(GameEndReason::RESIGNATION, PieceColor::WHITE);

        self::assertFalse($game->isRatedOutcome());
    }

    public function testClause3aEngineOpponentFails(): void
    {
        $game = $this->makeGame(rated: true, timeControl: TimeControl::realtime(300, 0), plyCount: 4, blackIsEngine: true);
        $game->finish(GameEndReason::ENGINE, PieceColor::WHITE);

        self::assertFalse($game->isRatedOutcome());
    }

    public function testClause3bHotSeatSameUserBothColoursFails(): void
    {
        $sameUser = new User('solo@example.com');
        $game = $this->makeGame(rated: true, timeControl: TimeControl::realtime(300, 0), plyCount: 4, whiteUser: $sameUser, blackUser: $sameUser);
        $game->finish(GameEndReason::ENGINE, PieceColor::WHITE);

        self::assertFalse($game->isRatedOutcome());
    }

    public function testClause4UnderRatedPlyFloorFails(): void
    {
        $game = $this->makeGame(rated: true, timeControl: TimeControl::realtime(300, 0), plyCount: 2);
        $game->finish(GameEndReason::TIMEOUT, PieceColor::WHITE);

        self::assertFalse($game->isRatedOutcome());
    }

    public function testClause5AbortedFails(): void
    {
        $game = $this->makeGame(rated: true, timeControl: TimeControl::realtime(300, 0), plyCount: 1);
        $game->finish(GameEndReason::ABORTED, null);

        self::assertFalse($game->isRatedOutcome());
    }

    public function testClause5NotFinishedFails(): void
    {
        $game = $this->makeGame(rated: true, timeControl: TimeControl::realtime(300, 0), plyCount: 4);

        self::assertFalse($game->isRatedOutcome());
    }

    /** A timeout at ply 2/3 has a real winner but is still unrated on clause 4 - ABORTED is a strict subset of unrated, not a synonym (sec 6.1 row 5). */
    public function testTimeoutBelowPlyFloorIsUnratedButNotAborted(): void
    {
        $game = $this->makeGame(rated: true, timeControl: TimeControl::realtime(300, 0), plyCount: 3);
        $game->finish(GameEndReason::TIMEOUT, PieceColor::BLACK);

        self::assertFalse($game->isRatedOutcome());
        self::assertNotSame(GameEndReason::ABORTED, $game->getEndReason());
    }

    private function makeGame(
        bool $rated,
        TimeControl $timeControl,
        int $plyCount,
        ?User $whiteUser = null,
        ?User $blackUser = null,
        bool $blackIsEngine = false,
    ): Game {
        $creator = new User('creator@example.com');
        $game = new Game($creator, OpponentType::MULTIPLAYER, $timeControl, $rated);

        new GamePlayer($game, PieceColor::WHITE, $whiteUser ?? new User('white@example.com'));
        new GamePlayer($game, PieceColor::BLACK, $blackIsEngine ? null : ($blackUser ?? new User('black@example.com')));

        $property = new \ReflectionProperty(Game::class, 'gameMoves');
        $property->setValue($game, new ArrayCollection(array_fill(0, $plyCount, new \stdClass())));

        return $game;
    }
}
