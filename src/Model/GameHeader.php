<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The shape the game page header (`templates/actions/_game_header.html.twig`)
 * renders: both seats plus the badges of the options the game was created with.
 */
final readonly class GameHeader
{
    /**
     * @param list<GameHeaderBadge> $badges
     */
    public function __construct(
        public GameHeaderPlayer $white,
        public GameHeaderPlayer $black,
        public array $badges,
    ) {
    }
}
