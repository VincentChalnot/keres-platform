<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Game\GameHeaderPresenter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `game_header(game, viewer)` - the players and option badges shown above the board. */
final class GameHeaderExtension extends AbstractExtension
{
    public function __construct(
        private readonly GameHeaderPresenter $gameHeaderPresenter,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('game_header', $this->gameHeaderPresenter->present(...)),
            new TwigFunction('game_board_header', $this->gameHeaderPresenter->presentForBoard(...)),
        ];
    }
}
