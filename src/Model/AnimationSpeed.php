<?php

declare(strict_types=1);

namespace App\Model;

/**
 * How fast the game board animates a piece moving to its square (Settings -> Board & gameplay).
 * The codes are the contract with the TypeScript client (`AnimationSpeed` in `models/types.ts`);
 * the actual durations live in `assets/board.css`.
 */
enum AnimationSpeed: string
{
    case INSTANT = 'instant';
    case FAST = 'fast';
    case SLOW = 'slow';
}
