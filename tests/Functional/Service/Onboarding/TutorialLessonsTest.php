<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service\Onboarding;

use App\Service\Locale\FrontendCatalogue;
use App\Service\Onboarding\TutorialContent;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The authored `public/tutorial/lessons.json` (format: docs/features.md, played by assets/typescript/src/tutorial.ts, which
 * re-checks everything on load): every board is a valid 83-byte board code of pieces the wire format knows (what the
 * client's `parseBoardCode` accepts), every square is a square, every text is a key of the catalogue in both locales and
 * every image is a file of `public/`.
 */
final class TutorialLessonsTest extends KernelTestCase
{
    private const string PUBLIC_DIR = __DIR__.'/../../../../public';

    /** @var list<string> */
    private array $problems = [];

    public function testTheCommittedLessonsAreValid(): void
    {
        self::bootKernel();
        $content = new TutorialContent(self::PUBLIC_DIR.'/tutorial');
        $lessons = $content->lessons();
        self::assertTrue($content->isAvailable());

        /** @var array<string, list<string>> $keys */
        $keys = [];
        $catalogue = self::getContainer()->get(FrontendCatalogue::class);
        self::assertInstanceOf(FrontendCatalogue::class, $catalogue);

        foreach (['en', 'fr'] as $locale) {
            $keys[$locale] = array_keys($catalogue->messages($locale));
        }

        $ids = [];
        $tracks = [];

        foreach ($lessons as $lesson) {
            $id = (string) ($lesson['id'] ?? '');
            $where = "lesson \"$id\"";
            self::assertNotSame('', $id);
            $this->check(!isset($ids[$id]), "$where: duplicate id");
            $ids[$id] = true;

            $track = $lesson['track'] ?? null;
            $this->check(\in_array($track, TutorialContent::TRACKS, true), "$where: track");
            $tracks[(string) $track] = true;

            $this->key($keys, $lesson['title'] ?? null, "$where: title");

            if (isset($lesson['text'])) {
                $this->key($keys, $lesson['text'], "$where: text");
            }

            foreach ($lesson['notes'] ?? [] as $note) {
                $this->key($keys, $note, "$where: note");
            }

            foreach ($lesson['images'] ?? [] as $image) {
                $this->check(\is_string($image) && is_file(self::PUBLIC_DIR.'/'.$image), "$where: image $image");
            }

            if (isset($lesson['board'])) {
                $this->board($lesson['board'], "$where: board");
            }

            $goal = $lesson['goal'] ?? [];

            switch ($goal['type'] ?? null) {
                case 'read':
                    break;
                case 'reach':
                    $this->check(isset($lesson['board']), "$where: a reach lesson needs a board");
                    $this->squaresOf($goal['stars'] ?? null, "$where: stars");
                    break;
                case 'capture':
                    $this->check(isset($lesson['board']), "$where: a capture lesson needs a board");
                    $this->squaresOf($goal['targets'] ?? null, "$where: targets");
                    break;
                case 'script':
                    $this->check(isset($lesson['board']), "$where: a script lesson needs a board");
                    $this->check(\is_array($goal['steps'] ?? null) && [] !== $goal['steps'], "$where: steps");

                    foreach ($goal['steps'] ?? [] as $number => $step) {
                        $at = "$where step ".($number + 1);
                        $this->check(\in_array($step['by'] ?? null, ['player', 'opponent'], true), "$at: by");
                        $this->squaresOf([$step['from'] ?? null, $step['to'] ?? null], "$at: from/to");
                        $this->board($step['board'] ?? null, "$at: board");
                        $this->key($keys, $step['text'] ?? null, "$at: text");

                        if (isset($step['prompt'])) {
                            $this->check(\in_array($step['prompt'], ['choose', 'select', 'select_top', 'select_stack'], true), "$at: prompt");
                        }
                    }
                    break;
                default:
                    $this->problems[] = "$where: goal.type";
            }
        }

        foreach (TutorialContent::TRACKS as $track) {
            $this->check(isset($tracks[$track]), "track $track has no lesson");
        }

        self::assertSame([], $this->problems, implode("\n", $this->problems));
    }

    private function check(bool $condition, string $problem): void
    {
        if (!$condition) {
            $this->problems[] = $problem;
        }
    }

    /**
     * @param array<string, list<string>> $keys
     */
    private function key(array $keys, mixed $key, string $where): void
    {
        foreach ($keys as $locale => $known) {
            $this->check(\is_string($key) && \in_array($key, $known, true), "$where: key ".json_encode($key)." missing in $locale");
        }
    }

    private function squaresOf(mixed $squares, string $where): void
    {
        $ok = \is_array($squares) && [] !== $squares;

        foreach (\is_array($squares) ? $squares : [] as $square) {
            $ok = $ok && \is_string($square) && 1 === preg_match('/^[A-I][1-9]$/', $square);
        }

        $this->check($ok, "$where: squares such as \"E2\"");
    }

    /** What the client's `parseBoardCode` + `decodePiece` / `encodePiece` round trip accept. */
    private function board(mixed $code, string $where): void
    {
        $bytes = \is_string($code) && 1 === preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $code) ? base64_decode($code, true) : false;

        if (false === $bytes || 83 !== \strlen($bytes)) {
            $this->problems[] = "$where: not an 83-byte board code";

            return;
        }

        for ($square = 0; $square < 81; ++$square) {
            $byte = \ord($bytes[$square]);
            $payload = $byte & 0x3F;
            $valid = 0 === $byte
                || (0 === ($byte & 0x80) && (0b111000 === $payload || (($payload & 0b111) >= 1 && ($payload & 0b111) <= 7 && ($payload >> 3) <= 7)));

            if (!$valid) {
                $this->problems[] = \sprintf('%s: square %d holds byte %d, not a piece', $where, $square, $byte);
            }
        }
    }
}
