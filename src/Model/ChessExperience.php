<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The answer to "Do you play chess?" (onboarding, D4 of `docs/onboarding-plan.md`): which tutorial track to offer and
 * whether the coach starts on. `SKIPPED` is an answer too: the question is never asked again.
 */
enum ChessExperience: string
{
    case PLAYER = 'player';
    case BEGINNER = 'beginner';
    case SKIPPED = 'skipped';
}
