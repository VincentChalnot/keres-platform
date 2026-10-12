<?php

declare(strict_types=1);

namespace App\Service\Onboarding;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The tutorial's authored material: one static SVG per slide plus the guided puzzle, dropped by the operator into
 * `public/tutorial/` (see `docs/features.md`). The tutorial page, its permanent link and the onboarding banner exist
 * only while every file is present.
 */
final readonly class TutorialContent
{
    /** Slide ids, in order: `public/tutorial/<id>.svg`, texts `slides.<id>` in the `tutorial` domain. */
    public const array SLIDES = ['board', 'like-chess', 'chess-pieces', 'new-pieces', 'chess-only', 'stacking', 'stack-moves'];

    public const string PUZZLE_FILE = 'puzzle.json';

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/tutorial')]
        private string $directory,
    ) {
    }

    public function isAvailable(): bool
    {
        foreach ([...array_map(static fn (string $id): string => $id.'.svg', self::SLIDES), self::PUZZLE_FILE] as $file) {
            if (!is_file($this->directory.'/'.$file)) {
                return false;
            }
        }

        return true;
    }
}
