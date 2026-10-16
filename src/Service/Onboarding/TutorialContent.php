<?php

declare(strict_types=1);

namespace App\Service\Onboarding;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The tutorial's authored material: `public/tutorial/lessons.json` (the lessons of both tracks, see `docs/features.md` for
 * the format) and the static images the lessons point to, dropped by the operator into `public/`. The tutorial page, its
 * permanent link and the home page's onboarding card exist only while the file is there and holds lessons.
 */
final readonly class TutorialContent
{
    public const string LESSONS_FILE = 'lessons.json';

    public const array TRACKS = ['chess', 'beginner'];

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/tutorial')]
        private string $directory,
    ) {
    }

    public function isAvailable(): bool
    {
        return [] !== $this->lessons();
    }

    /**
     * The raw lesson list of the file (empty when it is missing or not a `{"lessons": [...]}` document). The client
     * checks every lesson on load (`tutorial.ts`), the PHPUnit suite checks the committed file (`TutorialLessonsTest`).
     *
     * @return list<array<string, mixed>>
     */
    public function lessons(): array
    {
        $path = $this->directory.'/'.self::LESSONS_FILE;

        if (!is_file($path)) {
            return [];
        }

        try {
            $document = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $lessons = \is_array($document) ? ($document['lessons'] ?? null) : null;

        return \is_array($lessons) ? array_values(array_filter($lessons, 'is_array')) : [];
    }
}
