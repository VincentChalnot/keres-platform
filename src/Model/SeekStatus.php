<?php

declare(strict_types=1);

namespace App\Model;

/**
 * 04-matchmaking.md sec 2. `OPEN` is the only non-terminal state; the three
 * terminal transitions (`MATCHED`/`CANCELED`/`EXPIRED`) are never reversed -
 * a player who wants back in posts a new seek. `Live`/`Stale` liveness is a
 * predicate over `lastHeartbeatAt`, deliberately not a persisted state here.
 */
enum SeekStatus: int
{
    case OPEN = 0;
    case MATCHED = 1;
    case CANCELED = 2;
    case EXPIRED = 3;
}
