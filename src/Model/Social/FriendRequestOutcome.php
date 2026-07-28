<?php

declare(strict_types=1);

namespace App\Model\Social;

/**
 * `FriendshipManager::request()` result (05-social.md sec 3.3/3.4).
 * `created` is true only for a genuine new `PENDING` row (T1) - every other
 * branch (auto-accept T2, no-op T8, a silent block rejection) reuses an
 * existing row or writes nothing, and the controller renders 200 rather
 * than 201 for those.
 */
final readonly class FriendRequestOutcome
{
    private function __construct(
        public string $status,
        public bool $created,
    ) {
    }

    public static function pending(bool $created): self
    {
        return new self('pending', $created);
    }

    public static function accepted(): self
    {
        return new self('accepted', false);
    }
}
