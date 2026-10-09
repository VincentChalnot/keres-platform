<?php

declare(strict_types=1);

namespace App\Message;

/** Computes and stores the evaluation of the position after move number `$ply` of a game. */
readonly class EvaluateMoveMessage
{
    public function __construct(
        public string $gameUuid,
        public int $ply,
    ) {
    }
}
