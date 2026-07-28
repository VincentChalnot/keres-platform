<?php

declare(strict_types=1);

namespace App\Model\Glicko;

use App\Model\MultiplayerLimits;

/**
 * Immutable Glicko-2 state on the display scale (06-rating.md sec 2.1).
 * Every instance returned by `RatingUpdater` is already inflated
 * (06-rating.md sec 4.3).
 */
final readonly class Rating
{
    public function __construct(
        public float $rating = MultiplayerLimits::GLICKO_DEFAULT_RATING,
        public float $deviation = MultiplayerLimits::GLICKO_DEFAULT_RD,
        public float $volatility = MultiplayerLimits::GLICKO_DEFAULT_VOLATILITY,
        public int $gamesPlayed = 0,
        public ?\DateTimeImmutable $lastRatedAt = null,
    ) {
    }

    public static function initial(): self
    {
        return new self();
    }

    public function display(): int
    {
        return (int) round($this->rating);
    }

    /** Only meaningful on an inflated instance. */
    public function isProvisional(): bool
    {
        return $this->deviation > MultiplayerLimits::GLICKO_PROVISIONAL_RD;
    }
}
