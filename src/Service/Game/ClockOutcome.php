<?php

declare(strict_types=1);

namespace App\Service\Game;

/**
 * Result of `ClockManager::chargeAndSwap()` (03-time-control.md sec 2.4).
 * `flagged === true` means the move must be rejected and the game finalised
 * via `ClockManager::stop()` + `GameLifecycleManager::finaliseTimeout()`;
 * the mover's clock is left untouched in that case.
 */
final readonly class ClockOutcome
{
    public function __construct(
        public bool $flagged,
        public int $chargedMs,
        public ?int $remainingMsAfter,
        public ?int $deadlineAtMicros,
    ) {
    }
}
