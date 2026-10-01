<?php

declare(strict_types=1);

namespace App\Service\Matchmaking;

use App\Model\Request\SeekCreateRequest;
use App\Model\TimeControl;

/**
 * T11: extracted from `CreateSeekAction::resolveTimeControl()` (unchanged
 * behaviour) so `CreateInviteAction` can reuse the exact same
 * kind/initialSeconds/incrementSeconds/hoursPerMove coherence check instead
 * of duplicating it - both endpoints validate the same `SeekCreateRequest`
 * shape into a `TimeControl`, they just do different things with the
 * result afterwards.
 */
final readonly class TimeControlRequestResolver
{
    /** @return string|TimeControl the built value, or the `details.reason` string for a 422 `invalid_time_control` */
    public function resolve(SeekCreateRequest $r): TimeControl|string
    {
        $reason = match ($r->kind) {
            'unlimited' => (null !== $r->initialSeconds || null !== $r->incrementSeconds || null !== $r->hoursPerMove)
                ? 'unlimited carries a time-control field' : null,
            'realtime' => match (true) {
                null === $r->initialSeconds || null === $r->incrementSeconds => 'realtime requires initialSeconds and incrementSeconds',
                null !== $r->hoursPerMove => 'realtime carries hoursPerMove',
                default => null,
            },
            'correspondence' => match (true) {
                null === $r->hoursPerMove => 'correspondence requires hoursPerMove',
                null !== $r->initialSeconds || null !== $r->incrementSeconds => 'correspondence carries a realtime field',
                default => null,
            },
            default => 'unknown kind',
        };

        if (null !== $reason) {
            return $reason;
        }

        return match ($r->kind) {
            'unlimited' => TimeControl::unlimited(),
            'realtime' => TimeControl::realtime($r->initialSeconds, $r->incrementSeconds),
            default => TimeControl::correspondence($r->hoursPerMove),
        };
    }
}
