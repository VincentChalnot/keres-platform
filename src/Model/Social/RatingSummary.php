<?php

declare(strict_types=1);

namespace App\Model\Social;

use App\Model\Glicko\Rating;

/**
 * One rating-pool row for a profile/friends-list/search payload
 * (05-social.md sec 9.1). Built from an inflated `Rating` via
 * `fromRating()` - a user with zero rated games in a category still gets
 * a row here, because `RatingUpdater::currentRating()` returns
 * `Rating::initial()` (1500/350/0.06, `provisional: true`) rather than
 * omitting the pool (06-rating.md sec 5.3).
 */
final readonly class RatingSummary
{
    public function __construct(
        public int $rating,
        public bool $provisional,
        public int $games,
    ) {
    }

    public static function fromRating(Rating $rating): self
    {
        return new self($rating->display(), $rating->isProvisional(), $rating->gamesPlayed);
    }

    /** @return array{rating: int, provisional: bool, games: int} */
    public function toArray(): array
    {
        return [
            'rating' => $this->rating,
            'provisional' => $this->provisional,
            'games' => $this->games,
        ];
    }
}
