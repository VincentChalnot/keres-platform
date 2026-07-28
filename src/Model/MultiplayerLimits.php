<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Product knobs referenced throughout docs/multiplayer/. A final class with
 * no state and no Doctrine mapping (00-overview.md sec 6) - compared against
 * at write time so retuning a constant changes future rows without a
 * migration.
 */
final class MultiplayerLimits
{
    /** L: refund of the inbound network leg on the move path (03-time-control.md sec 3.1). */
    public const int CLOCK_LAG_COMPENSATION_MS = 100;

    /** G: slack before the adjudicator declares a flag (03-time-control.md sec 3.1). */
    public const int CLOCK_EXPIRY_GRACE_MS = 500;

    /** F: the "did anyone turn up" abort clamp on each side's first move (03-time-control.md sec 7.1). */
    public const int FIRST_MOVE_TIMEOUT_SECONDS = 30;

    /** Per side; total 4. Game::hasReachedRatedPlyFloor() (03-time-control.md sec 7.2, 06-rating.md sec 6.1). */
    public const int RATED_MIN_PLIES = 2;

    private function __construct()
    {
    }
}
