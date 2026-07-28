<?php

declare(strict_types=1);

namespace App\Model;

/**
 * `Game.whiteWins`/`Game.draw` say *what* happened; this says *how*.
 * Int values fixed by 06-rating.md sec 6.2's outcome table - do not renumber,
 * `Game::finish()` and the P0.2-era migration backfill both depend on them.
 */
enum GameEndReason: int
{
    case NONE = 0;
    case ENGINE = 1;
    case RESIGNATION = 2;
    case TIMEOUT = 3;
    case ABANDONMENT = 4;
    case DRAW_AGREED = 5;
    case ABORTED = 6;
}
