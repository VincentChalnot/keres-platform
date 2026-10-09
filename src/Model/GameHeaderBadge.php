<?php

declare(strict_types=1);

namespace App\Model;

/** One option tag of the game page header; `$tone` is a Bulma colour modifier (`is-info`, `is-light`...). */
final readonly class GameHeaderBadge
{
    public function __construct(
        public string $label,
        public string $tone = 'is-light',
        public ?string $title = null,
    ) {
    }
}
