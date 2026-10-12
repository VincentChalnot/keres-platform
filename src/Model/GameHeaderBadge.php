<?php

declare(strict_types=1);

namespace App\Model;

/**
 * One option tag of the game page header; `$tone` says how much it stands out (the CSS classes are mapped in `_game_badge.html.twig`).
 * `$label` and `$title` are display text already translated by `GameHeaderPresenter`.
 */
final readonly class GameHeaderBadge
{
    public function __construct(
        public string $label,
        public GameHeaderBadgeTone $tone = GameHeaderBadgeTone::NEUTRAL,
        public ?string $title = null,
    ) {
    }
}
