<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Rating;

use App\Model\Glicko\GameOutcome;
use App\Model\Glicko\Rating;
use App\Service\Rating\Glicko2Calculator;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures F1-F9 from 06-rating.md sec 9.4 / sec 2.5. Tolerance 1.0e-4 on
 * the display scale per the chapter's own assertion convention.
 */
final class Glicko2CalculatorTest extends TestCase
{
    private const float DELTA = 1.0e-4;

    private Glicko2Calculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new Glicko2Calculator();
    }

    /** F1 - Glickman's paper, canonical multi-opponent example. */
    public function testMultiOpponentMatchesGlickmansPaper(): void
    {
        $current = new Rating(rating: 1500, deviation: 200, volatility: 0.06);
        $outcomes = [
            new GameOutcome(new Rating(rating: 1400, deviation: 30), GameOutcome::WIN),
            new GameOutcome(new Rating(rating: 1550, deviation: 100), GameOutcome::LOSS),
            new GameOutcome(new Rating(rating: 1700, deviation: 300), GameOutcome::LOSS),
        ];

        $change = $this->calculator->rate($current, $outcomes, new \DateTimeImmutable('2026-01-01'));

        self::assertEqualsWithDelta(1464.0506705393, $change->after->rating, self::DELTA);
        self::assertEqualsWithDelta(151.5165241239, $change->after->deviation, self::DELTA);
        self::assertEqualsWithDelta(0.0599959843, $change->after->volatility, self::DELTA);
        self::assertSame(3, $change->after->gamesPlayed);
    }

    /** F2a - single-opponent production path, provisional winner. */
    public function testSingleOpponentProvisionalWinnerGainsLarge(): void
    {
        $current = new Rating(rating: 1500, deviation: 350, volatility: 0.06);
        $opponent = new Rating(rating: 1500, deviation: 50);

        $change = $this->calculator->rateSingle($current, $opponent, GameOutcome::WIN, new \DateTimeImmutable('2026-01-01'));

        self::assertEqualsWithDelta(1675.0756982644, $change->after->rating, self::DELTA);
        self::assertEqualsWithDelta(248.1705415141, $change->after->deviation, self::DELTA);
        self::assertEqualsWithDelta(0.0599991770, $change->after->volatility, self::DELTA);
    }

    /** F2b - the mirror of F2a; together they pin the non-zero-sum property. */
    public function testSingleOpponentEstablishedLoserLosesSmall(): void
    {
        $current = new Rating(rating: 1500, deviation: 50, volatility: 0.06);
        $opponent = new Rating(rating: 1500, deviation: 350);

        $change = $this->calculator->rateSingle($current, $opponent, GameOutcome::LOSS, new \DateTimeImmutable('2026-01-01'));

        self::assertEqualsWithDelta(1495.0245785290, $change->after->rating, self::DELTA);
        self::assertEqualsWithDelta(50.8295784813, $change->after->deviation, self::DELTA);
        self::assertEqualsWithDelta(0.0600000000, $change->after->volatility, self::DELTA);
    }

    public function testDeltasDoNotSumToZero(): void
    {
        $now = new \DateTimeImmutable('2026-01-01');
        $white = $this->calculator->rateSingle(
            new Rating(rating: 1500, deviation: 350, volatility: 0.06),
            new Rating(rating: 1500, deviation: 50),
            GameOutcome::WIN,
            $now,
        );
        $black = $this->calculator->rateSingle(
            new Rating(rating: 1500, deviation: 50, volatility: 0.06),
            new Rating(rating: 1500, deviation: 350),
            GameOutcome::LOSS,
            $now,
        );

        self::assertSame(175, $white->delta());
        self::assertSame(-5, $black->delta());
        self::assertNotSame(0, $white->delta() + $black->delta());
    }

    /** F3 - the `ln(delta^2 - phi^2 - v)` volatility-solver bracket branch. */
    public function testUpsetWinTakesLnBracketBranch(): void
    {
        $current = new Rating(rating: 1500, deviation: 350, volatility: 0.06);
        $opponent = new Rating(rating: 2000, deviation: 50);

        $change = $this->calculator->rateSingle($current, $opponent, GameOutcome::WIN, new \DateTimeImmutable('2026-01-01'));

        self::assertEqualsWithDelta(2046.0861225696, $change->after->rating, self::DELTA);
        self::assertEqualsWithDelta(318.8241168590, $change->after->deviation, self::DELTA);
        self::assertEqualsWithDelta(0.0600075160, $change->after->volatility, self::DELTA);
    }

    /** F4 - a draw between equals: rating exactly unchanged, RD moves *up* (guards a "draws shrink RD" regression). */
    public function testDrawBetweenEqualsLeavesRatingUnchangedButRdRises(): void
    {
        $current = new Rating(rating: 1600, deviation: 60, volatility: 0.06);
        $opponent = new Rating(rating: 1600, deviation: 60);

        $change = $this->calculator->rateSingle($current, $opponent, GameOutcome::DRAW, new \DateTimeImmutable('2026-01-01'));

        self::assertEqualsWithDelta(1600.0000, $change->after->rating, self::DELTA);
        self::assertEqualsWithDelta(60.0153, $change->after->deviation, self::DELTA);
        self::assertGreaterThan(60.0, $change->after->deviation);
    }

    /** F5 - ordinary established win, small gain. */
    public function testOrdinaryEstablishedWinIsSmall(): void
    {
        $current = new Rating(rating: 1700, deviation: 45, volatility: 0.06);
        $opponent = new Rating(rating: 1500, deviation: 45);

        $change = $this->calculator->rateSingle($current, $opponent, GameOutcome::WIN, new \DateTimeImmutable('2026-01-01'));

        self::assertEqualsWithDelta(1702.9099, $change->after->rating, self::DELTA);
        self::assertEqualsWithDelta(45.9002, $change->after->deviation, self::DELTA);
    }

    /** F6 - sec 4 closed-form inflation over fractional rating periods. */
    public function testInflationClosedFormOverFractionalPeriods(): void
    {
        $lastRated = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $rating = new Rating(rating: 1500, deviation: 60, volatility: 0.06, lastRatedAt: $lastRated);

        self::assertEqualsWithDelta(60.8986, $this->calculator->inflate($rating, $lastRated->modify('+7 days'))->deviation, self::DELTA);
        self::assertEqualsWithDelta(63.7621, $this->calculator->inflate($rating, $lastRated->modify('+30 days'))->deviation, self::DELTA);
        self::assertEqualsWithDelta(96.2539, $this->calculator->inflate($rating, $lastRated->modify('+365 days'))->deviation, self::DELTA);
    }

    /** F7 - the GLICKO_MAX_RD clamp. */
    public function testInflationClampsAtMaxRd(): void
    {
        $lastRated = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $rating = new Rating(rating: 1500, deviation: 340, volatility: 0.50, lastRatedAt: $lastRated);

        $inflated = $this->calculator->inflate($rating, $lastRated->modify('+700 days'));

        self::assertEqualsWithDelta(350.0, $inflated->deviation, self::DELTA);
    }

    /** F8 - a never-rated row inflates to itself. */
    public function testInflationIsIdentityWhenNeverRated(): void
    {
        $rating = new Rating(rating: 1500, deviation: 350, volatility: 0.06, lastRatedAt: null);

        $inflated = $this->calculator->inflate($rating, new \DateTimeImmutable('2026-01-01'));

        self::assertSame($rating, $inflated);
    }

    /** F9 - clock skew clamps `t` to 0: identity, not a negative inflation. */
    public function testInflationIsIdentityUnderClockSkew(): void
    {
        $lastRated = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $rating = new Rating(rating: 1500, deviation: 60, volatility: 0.06, lastRatedAt: $lastRated);

        $inflated = $this->calculator->inflate($rating, $lastRated->modify('-1 day'));

        self::assertSame($rating, $inflated);
    }

    /** Generic assertions over F1-F5 (sec 9.4): evidence always reduces uncertainty. */
    public function testEvidenceAlwaysReducesUncertainty(): void
    {
        $now = new \DateTimeImmutable('2026-01-01');
        $current = new Rating(rating: 1500, deviation: 200, volatility: 0.06);
        $change = $this->calculator->rateSingle($current, new Rating(rating: 1500, deviation: 80), GameOutcome::WIN, $now);

        self::assertGreaterThan(0.0, $change->after->deviation);
        self::assertLessThanOrEqual(350.0, $change->after->deviation);
    }
}
