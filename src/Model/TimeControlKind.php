<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The three clock code paths (00-overview.md D3). No Bronstein, no simple
 * delay, no extra time at move 40 - see 03-time-control.md sec 1.1.
 */
enum TimeControlKind: int
{
    case UNLIMITED = 0;
    case REALTIME = 1;
    case CORRESPONDENCE = 2;
}
