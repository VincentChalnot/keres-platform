<?php

declare(strict_types=1);

namespace App\Model;

/**
 * One seat of the game-page header (`GameHeaderPresenter`): who plays it,
 * as already-resolved display text (translated into the viewer's locale by
 * the presenter) - the template never decides identity.
 */
final readonly class GameHeaderPlayer
{
    public function __construct(
        public PieceColor $color,
        /** What to show: a username, "Keres Bot (level 3)", the translated "AI (level 3)", "Player"... */
        public string $label,
        /** `ai` (engine opponent), `bot` (tournament bot account), `human`, or `anonymous` (identity withheld from the viewer). */
        public string $kind,
        /** Profile handle to link to; null unless `$kind` is `human`. */
        public ?string $username = null,
        /** Avatar image URL; only for `human` and `bot` seats that have one. */
        public ?string $avatarUrl = null,
        /** Seat rating; only filled by `GameHeaderPresenter::presentForBoard()`. */
        public ?GameHeaderRating $rating = null,
    ) {
    }
}
