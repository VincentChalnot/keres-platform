<?php

declare(strict_types=1);

namespace App\Model\Glicko;

/** One finished game from one player's point of view (06-rating.md sec 9.1). */
final readonly class GameOutcome
{
    public const float WIN = 1.0;
    public const float DRAW = 0.5;
    public const float LOSS = 0.0;

    /**
     * @param Rating $opponent pre-game, already inflated
     * @param float $score WIN | DRAW | LOSS
     */
    public function __construct(public Rating $opponent, public float $score)
    {
    }
}
