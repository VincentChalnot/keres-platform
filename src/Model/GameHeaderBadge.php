<?php

declare(strict_types=1);

namespace App\Model;

/**
 * One option tag of the game page header; `$tone` is the design-system tag modifier classes added to `.tag` (`tag--soft tag--info`...), empty for the neutral tag.
 * `$label` and `$title` are display text already translated by `GameHeaderPresenter`.
 */
final readonly class GameHeaderBadge
{
    public function __construct(
        public string $label,
        public string $tone = '',
        public ?string $title = null,
    ) {
    }
}
