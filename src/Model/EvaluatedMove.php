<?php

declare(strict_types=1);

namespace App\Model;

/**
 * One legal move with the engine's level-10 score of the position it leads
 * to (`/evaluate-moves`): White's point of view, engine units.
 */
readonly class EvaluatedMove
{
    public function __construct(
        public MoveData $move,
        public int $score,
    ) {
    }
}
