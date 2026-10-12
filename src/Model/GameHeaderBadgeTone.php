<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The visual weight of a game header option tag. Presentation-neutral on purpose: the CSS classes of each tone
 * live in one place, `templates/actions/_game_badge.html.twig`.
 */
enum GameHeaderBadgeTone: string
{
    case NEUTRAL = 'neutral';
    case INFO = 'info';
    case WARNING = 'warning';
}
