<?php

declare(strict_types=1);

namespace App\Model;

/**
 * T13: the one shape every "games" rendering (dashboard widget, `/games`,
 * a profile's game history) builds its row from - `GameListPresenter`
 * produces it, `templates/actions/_game_row.html.twig` renders it. Never
 * built directly in a template; always via `game_list_row()` (Twig
 * function, see `App\Twig\GameListExtension`).
 */
final readonly class GameListRow
{
    public function __construct(
        public string $uuid,
        /** Who plays and which options the game has - the same header as the game page, rendered compact. */
        public GameHeader $header,
        /** Display text in the viewer's locale (built at render time, never stored). Null exactly when `$isGameOver` is true - see `$resultLabel`. */
        public ?string $turnLabel,
        /** Null exactly when `$isGameOver` is false - see `$turnLabel`. */
        public ?string $resultLabel,
        /** Null for unlimited time control, or once the game is over. */
        public ?string $timeRemainingLabel,
        public \DateTimeImmutable $lastActivityAt,
        public bool $isGameOver,
        /** True when the turn tag is "your turn" (subject's own, non hot-seat, game in progress): styles the tag. */
        public bool $isSubjectTurn = false,
    ) {
    }
}
