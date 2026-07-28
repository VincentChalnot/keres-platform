<?php

declare(strict_types=1);

namespace App\Model;

/**
 * A seek's colour ask - distinct from `PieceColor`, which a game always has
 * resolved. `RANDOM` is compatible with everything including another
 * `RANDOM` (04-matchmaking.md sec 3.2); colour is resolved to a concrete
 * `PieceColor` exactly once, by `GameFactory`, at pairing time (sec 3.6).
 */
enum ColorPreference: int
{
    case WHITE = 0;
    case BLACK = 1;
    case RANDOM = 2;
}
