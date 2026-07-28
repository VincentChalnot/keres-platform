<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched with a `DelayStamp(ttl * 1000)` inside the create transaction
 * (04-matchmaking.md sec 2/4.4). Idempotent by the `status = OPEN` guard in
 * the handler - never revoked on early cancellation/match, so redelivery
 * and a stale seek are both harmless no-ops.
 */
readonly class ExpireSeekMessage
{
    public function __construct(
        public string $seekUuid,
    ) {
    }
}
