<?php

declare(strict_types=1);

namespace App\Model;

/**
 * D2: one rating pool per speed category (00-overview.md decision register).
 * Frozen on `Game.speedCategory` at creation - see 03-time-control.md sec 1.1
 * and 01-domain-model.md sec 3.2 for why it is persisted rather than derived.
 */
enum SpeedCategory: int
{
    case BULLET = 0;
    case BLITZ = 1;
    case RAPID = 2;
    case CLASSICAL = 3;
    case CORRESPONDENCE = 4;
}
