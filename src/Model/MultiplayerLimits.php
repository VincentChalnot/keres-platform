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

    /** 05-social.md sec 3.5: cooldown before a declined request may be re-sent. */
    public const int FRIEND_REQUEST_COOLDOWN_SECONDS = 604800;

    /** 05-social.md sec 2.2: shorter is a paginated dump of the user table. */
    public const int USERNAME_MIN_SEARCH_PREFIX = 3;

    /** 05-social.md sec 5.4 (Phase 7 - challenges). */
    public const int MAX_OUTBOUND_CHALLENGES = 10;

    /** 05-social.md sec 7.2 (Phase 6 - draw offers). */
    public const int DRAW_OFFER_COOLDOWN_PLIES = 6;

    /** 05-social.md sec 9.1: window for the profile page's online dot. */
    public const int PROFILE_ONLINE_WINDOW_SECONDS = 300;

    /** 05-social.md sec 9.3. */
    public const int PROFILE_GAMES_PER_PAGE = 20;

    /** 06-rating.md sec 2.1: bounds how fast sigma may move. */
    public const float GLICKO_TAU = 0.5;

    /** 06-rating.md sec 3.1/8.1: `RD > this` is provisional, strictly greater so exactly 110 is established. */
    public const int GLICKO_PROVISIONAL_RD = 110;

    /** 06-rating.md sec 2.1/2.4: the read-time and write-time RD ceiling. */
    public const int GLICKO_MAX_RD = 350;

    /** 06-rating.md sec 2.1: default deviation for a player with no `user_rating` row. */
    public const float GLICKO_DEFAULT_RD = 350.0;

    /** 06-rating.md sec 2.1: default volatility for a player with no `user_rating` row. */
    public const float GLICKO_DEFAULT_VOLATILITY = 0.06;

    /** 06-rating.md sec 4.1: the `t` denominator - fixtures F6/F7 pin this to 7 (a weekly period, Glickman's own convention). */
    public const int GLICKO_RATING_PERIOD_DAYS = 7;

    /** 06-rating.md sec 8.4 / open question 2: leaderboard eligibility floor - a lucky short run clears `RD` under 110 well before 20 games. */
    public const int LEADERBOARD_MIN_GAMES = 20;

    /** 06-rating.md sec 8.4 / open question 2: leaderboard recency window - stops a dormant account squatting the top before RD inflation alone would (~18 months). */
    public const int LEADERBOARD_ACTIVE_DAYS = 90;

    private function __construct()
    {
    }
}
