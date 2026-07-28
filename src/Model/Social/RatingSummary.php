<?php

declare(strict_types=1);

namespace App\Model\Social;

use App\Model\MultiplayerLimits;
use App\Model\SpeedCategory;

/**
 * One rating-pool row for a profile/friends-list/search payload
 * (05-social.md sec 9.1). Phase 5 (`06-rating.md`) introduces `UserRating`
 * and `Glicko2Calculator::inflate()`; until then every user renders as
 * `1500?` with 0 games in every pool - "not an error and not a gap"
 * (sec 9.1), because no `UserRating` row exists anywhere yet.
 */
final readonly class RatingSummary
{
    public function __construct(
        public int $rating,
        public bool $provisional,
        public int $games,
    ) {
    }

    /** @return array<string, self> keyed by the lowercase SpeedCategory case name (bullet/blitz/rapid/classical/correspondence) */
    public static function defaultsForAllCategories(): array
    {
        $out = [];

        foreach (SpeedCategory::cases() as $category) {
            $out[strtolower($category->name)] = new self(MultiplayerLimits::GLICKO_DEFAULT_RATING, true, 0);
        }

        return $out;
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
