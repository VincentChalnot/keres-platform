<?php

declare(strict_types=1);

namespace App\Model\Matchmaking;

use App\Entity\Game;

/**
 * Internal result of one pairing-transaction attempt (04-matchmaking.md sec
 * 3.5). `$skipped` distinguishes "the pool has nobody" from "my only
 * candidate is contended", which is what makes the bounded retry (sec
 * 3.1/3.5, §7 race 1) possible.
 */
final readonly class PairOutcome
{
    public function __construct(
        public ?Game $game,
        public bool $skipped,
    ) {
    }
}
