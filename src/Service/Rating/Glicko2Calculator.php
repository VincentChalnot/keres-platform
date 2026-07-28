<?php

declare(strict_types=1);

namespace App\Service\Rating;

use App\Model\Glicko\GameOutcome;
use App\Model\Glicko\Rating;
use App\Model\Glicko\RatingChange;
use App\Model\MultiplayerLimits;

/**
 * Pure Glicko-2 (06-rating.md sec 2). No Doctrine, no repositories, no
 * clock, no configuration beyond `MultiplayerLimits`. Stateless and
 * readonly, therefore safe under FrankenPHP worker mode with no
 * `kernel.reset` tag (00-overview.md sec 6, landmine 6).
 */
final readonly class Glicko2Calculator
{
    /** 400/ln(10), the published literal - sec 2.1: pinning it makes worked examples reproducible against every other implementation. */
    public const float SCALE = 173.7178;

    private const float CONVERGENCE_EPSILON = 1.0e-6;
    private const int MAX_ITERATIONS = 100;

    /**
     * Section 4. Identity when `lastRatedAt` is null or `$at` precedes it
     * (clock skew clamps `t` to 0).
     */
    public function inflate(Rating $rating, \DateTimeImmutable $at): Rating
    {
        if (null === $rating->lastRatedAt || $at < $rating->lastRatedAt) {
            return $rating;
        }

        $elapsedSeconds = $at->getTimestamp() - $rating->lastRatedAt->getTimestamp();
        $t = max(0.0, $elapsedSeconds / (MultiplayerLimits::GLICKO_RATING_PERIOD_DAYS * 86400));

        $phi = $rating->deviation / self::SCALE;
        $phiInflated = min(
            sqrt($phi ** 2 + $rating->volatility ** 2 * $t),
            MultiplayerLimits::GLICKO_MAX_RD / self::SCALE,
        );

        return new Rating(
            rating: $rating->rating,
            deviation: $phiInflated * self::SCALE,
            volatility: $rating->volatility,
            gamesPlayed: $rating->gamesPlayed,
            lastRatedAt: $rating->lastRatedAt,
        );
    }

    /**
     * Full update, sections 2.2-2.4. `$current` MUST already be inflated to
     * `$at`, and every `GameOutcome::$opponent` MUST be the opponent's
     * pre-game inflated rating.
     *
     * @param non-empty-list<GameOutcome> $outcomes
     */
    public function rate(Rating $current, array $outcomes, \DateTimeImmutable $at): RatingChange
    {
        $mu = ($current->rating - 1500) / self::SCALE;
        $phi = $current->deviation / self::SCALE;

        $vInverseSum = 0.0;
        $s = 0.0;

        foreach ($outcomes as $outcome) {
            $muJ = ($outcome->opponent->rating - 1500) / self::SCALE;
            $phiJ = $outcome->opponent->deviation / self::SCALE;
            $gPhiJ = $this->g($phiJ);
            $eJ = $this->expectedScore($mu, $muJ, $phiJ);

            $vInverseSum += $gPhiJ ** 2 * $eJ * (1 - $eJ);
            $s += $gPhiJ * ($outcome->score - $eJ);
        }

        $v = 1 / $vInverseSum;
        $delta = $v * $s;

        $sigmaPrime = $this->solveVolatility($delta, $phi, $v, $current->volatility);

        $phiStar = sqrt($phi ** 2 + $sigmaPrime ** 2);
        $phiPrime = 1 / sqrt(1 / $phiStar ** 2 + 1 / $v);
        $muPrime = $mu + $phiPrime ** 2 * $s;

        $rPrime = self::SCALE * $muPrime + 1500;
        $rdPrime = min(self::SCALE * $phiPrime, MultiplayerLimits::GLICKO_MAX_RD);

        $after = new Rating(
            rating: $rPrime,
            deviation: $rdPrime,
            volatility: $sigmaPrime,
            gamesPlayed: $current->gamesPlayed + \count($outcomes),
            lastRatedAt: $at,
        );

        return new RatingChange($current, $after);
    }

    /** The production path: exactly one opponent. */
    public function rateSingle(Rating $current, Rating $opponent, float $score, \DateTimeImmutable $at): RatingChange
    {
        return $this->rate($current, [new GameOutcome($opponent, $score)], $at);
    }

    /** Discounts an opponent whose own rating is uncertain (sec 2.2). */
    private function g(float $phi): float
    {
        return 1 / sqrt(1 + 3 * $phi ** 2 / \M_PI ** 2);
    }

    /** Expected score against one opponent (sec 2.2). */
    private function expectedScore(float $mu, float $muJ, float $phiJ): float
    {
        return 1 / (1 + exp(-$this->g($phiJ) * ($mu - $muJ)));
    }

    /**
     * The Illinois variant of regula falsi (sec 2.3). `fC * fB <= 0`, not
     * `< 0` per the paper: folding the exact-zero case into the sign-change
     * branch removes a path where `fA` halves forever after landing on the
     * root.
     */
    private function solveVolatility(float $delta, float $phi, float $v, float $sigma): float
    {
        $tau = MultiplayerLimits::GLICKO_TAU;
        $a = log($sigma ** 2);

        $f = static function (float $x) use ($delta, $phi, $v, $a, $tau): float {
            $eX = exp($x);

            return ($eX * ($delta ** 2 - $phi ** 2 - $v - $eX)) / (2 * ($phi ** 2 + $v + $eX) ** 2)
                - ($x - $a) / $tau ** 2;
        };

        $bigA = $a;

        if ($delta ** 2 > $phi ** 2 + $v) {
            $bigB = log($delta ** 2 - $phi ** 2 - $v);
        } else {
            $k = 1;

            while ($f($a - $k * $tau) < 0) {
                ++$k;
            }

            $bigB = $a - $k * $tau;
        }

        $fA = $f($bigA);
        $fB = $f($bigB);

        $iterations = 0;

        while (abs($bigB - $bigA) > self::CONVERGENCE_EPSILON && $iterations < self::MAX_ITERATIONS) {
            $bigC = $bigA + ($bigA - $bigB) * $fA / ($fB - $fA);
            $fC = $f($bigC);

            if ($fC * $fB <= 0) {
                $bigA = $bigB;
                $fA = $fB;
            } else {
                $fA /= 2; // Illinois correction: retains superlinear convergence.
            }

            $bigB = $bigC;
            $fB = $fC;
            ++$iterations;
        }

        if ($iterations >= self::MAX_ITERATIONS) {
            // A stale volatility beats aborting a game finalisation (sec 2.3).
            return $sigma;
        }

        return exp($bigA / 2);
    }
}
