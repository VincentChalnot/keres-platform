<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Game\GameListPresenter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `game_list_row(game, subject, isSelf)` - the one entry point every games
 * listing template uses to get a `GameListRow` (T13). Mirrors
 * `NotificationExtension`'s shape.
 */
final class GameListExtension extends AbstractExtension
{
    public function __construct(
        private readonly GameListPresenter $gameListPresenter,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('game_list_row', $this->gameListPresenter->present(...)),
        ];
    }
}
