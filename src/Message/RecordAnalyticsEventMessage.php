<?php

declare(strict_types=1);

namespace App\Message;

use App\Model\AnalyticsEventType;

/**
 * T6: routed to `async` (messenger.yaml) - the write path stays a single
 * cheap dispatch (no query, no join, no wait for a flush), even from the
 * hottest call site (a move played on every ply). `occurredAt` is captured
 * by the caller at dispatch time (cheap, no I/O) rather than by the handler
 * when it eventually runs, so a busy worker queue never skews event
 * timestamps.
 */
readonly class RecordAnalyticsEventMessage
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public AnalyticsEventType $type,
        public string $occurredAt,
        public ?string $userId = null,
        public ?string $gameUuid = null,
        public array $payload = [],
    ) {
    }
}
