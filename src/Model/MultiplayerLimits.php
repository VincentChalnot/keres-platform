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

    /** 04-matchmaking.md sec 1.3: TTL for OPEN/UNLIMITED seeks. */
    public const int SEEK_TTL_SECONDS = 600;

    /** 04-matchmaking.md sec 1.3/9 open question 5: correspondence seeks reuse this (no dedicated constant yet). */
    public const int CHALLENGE_TTL_SECONDS = 86400;

    /** 04-matchmaking.md sec 4.2: a seek drops out of the pool/listing after this many seconds of silence. */
    public const int SEEK_STALE_AFTER_SECONDS = 25;

    /** 04-matchmaking.md sec 4.2: the client heartbeat period in milliseconds; also the pairing-retry granularity. */
    public const int SEEK_HEARTBEAT_INTERVAL_MS = 10000;

    /** 04-matchmaking.md sec 3.3: w(t) = min(WINDOW_MAX, WINDOW_BASE + WIDEN_PER_SECOND * t). */
    public const int QUICK_PAIR_WINDOW_BASE = 200;

    public const int QUICK_PAIR_WINDOW_MAX = 1000;

    public const int QUICK_PAIR_WIDEN_PER_SECOND = 50;

    /**
     * Literal snapshot for every seek until Phase 5 adds `UserRating`
     * (06-rating.md sec 2.1). Also the permanent snapshot for `UNLIMITED`
     * seeks thereafter (04-matchmaking.md sec 1.3): "everyone matches
     * everyone" - never a live read, so a client can never assert a rating
     * to slip a window.
     */
    public const int GLICKO_DEFAULT_RATING = 1500;

    private function __construct()
    {
    }
}
