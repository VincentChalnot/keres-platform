<?php

declare(strict_types=1);

namespace App\Model\Matchmaking;

use App\Entity\Seek;
use Symfony\Component\Uid\Uuid;

/** Internal result of the create-time dedupe/replace transaction (04-matchmaking.md sec 6.2). */
final readonly class SeekCreationResult
{
    public function __construct(
        public Seek $seek,
        public bool $deduped,
        public ?Uuid $replacedSeekUuid = null,
    ) {
    }
}
