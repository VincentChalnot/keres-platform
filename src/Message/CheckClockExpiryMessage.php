<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched with a `DelayStamp` to `moveDeadlineAt + (L+G)*1000` ms from the
 * move transaction (03-time-control.md sec 4.1 step 21, sec 5.2 path a).
 * The handler discards on a staleness mismatch before touching the
 * adjudicator (sec 5.3) - neither field is redundant: `expectedMoveCount`
 * catches the ordinary case (the player moved), `deadlineAtMicros` catches a
 * deadline that changed without a move (a re-armed timer).
 */
readonly class CheckClockExpiryMessage
{
    public function __construct(
        public string $gameUuid,
        public int $expectedMoveCount,
        public int $deadlineAtMicros,
    ) {
    }
}
