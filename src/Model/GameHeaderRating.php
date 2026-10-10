<?php

declare(strict_types=1);

namespace App\Model;

/** A seat's rating on the game page: the pool rating of its game's speed category. */
final readonly class GameHeaderRating
{
    public function __construct(
        public int $value,
        public bool $provisional,
        /** Rating change caused by this game; null while the game has not been rated yet. */
        public ?int $delta = null,
    ) {
    }
}
