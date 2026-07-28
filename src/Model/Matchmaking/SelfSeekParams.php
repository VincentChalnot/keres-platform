<?php

declare(strict_types=1);

namespace App\Model\Matchmaking;

use App\Model\ColorPreference;
use App\Model\TimeControlKind;

/**
 * The `:self*` parameters of the candidate query (04-matchmaking.md sec
 * 3.4), read from the acting seek row under its own lock in step 1 of the
 * pairing transaction (sec 3.5) - never re-read mid-transaction.
 */
final readonly class SelfSeekParams
{
    public function __construct(
        public int $id,
        public string $userId,
        public TimeControlKind $kind,
        public ?int $speedCategory,
        public ?int $initialSeconds,
        public ?int $incrementSeconds,
        public ?int $daysPerMove,
        public bool $rated,
        public ColorPreference $colorPreference,
        public bool $autoWiden,
        public int $ratingSnapshot,
        public ?int $ratingMin,
        public ?int $ratingMax,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row raw `seek` row as returned by DBAL */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            userId: (string) $row['user_id'],
            kind: TimeControlKind::from((int) $row['time_control_kind']),
            speedCategory: null !== $row['speed_category_value'] ? (int) $row['speed_category_value'] : null,
            initialSeconds: null !== $row['initial_seconds'] ? (int) $row['initial_seconds'] : null,
            incrementSeconds: null !== $row['increment_seconds'] ? (int) $row['increment_seconds'] : null,
            daysPerMove: null !== $row['days_per_move'] ? (int) $row['days_per_move'] : null,
            rated: (bool) $row['rated'],
            colorPreference: ColorPreference::from((int) $row['color_preference_value']),
            autoWiden: (bool) $row['auto_widen'],
            ratingSnapshot: (int) $row['rating_snapshot'],
            ratingMin: null !== $row['rating_min'] ? (int) $row['rating_min'] : null,
            ratingMax: null !== $row['rating_max'] ? (int) $row['rating_max'] : null,
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
        );
    }
}
