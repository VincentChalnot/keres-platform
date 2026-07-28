<?php

declare(strict_types=1);

namespace App\Model\Matchmaking;

use App\Entity\Game;
use App\Entity\Seek;

/** `{"seek":{...},"matched":null|{"gameUuid":...},"deduped":bool}` (09-api-reference.md sec 4.1). */
final readonly class SeekCreateOutcome
{
    public function __construct(
        public Seek $seek,
        public ?Game $matchedGame,
        public bool $deduped,
    ) {
    }
}
