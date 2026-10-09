<?php

declare(strict_types=1);

namespace App\Model;

/**
 * One player-facing news item, already translated into the request locale.
 */
final readonly class Announcement
{
    public function __construct(
        public string $id,
        public \DateTimeImmutable $date,
        public string $title,
        public string $body,
    ) {
    }
}
