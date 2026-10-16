<?php

declare(strict_types=1);

namespace App\Tests\Unit\Help;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The movement diagrams of the help drawer, the piece-info panel and /tutorial (`public/help/`, authored with the admin
 * board editor from the squares the engine reports - see docs/features.md): all there, each a small standalone board SVG.
 */
final class HelpDiagramsTest extends TestCase
{
    private const int MAX_BYTES = 40_000;

    /**
     * @return iterable<string, array{string}>
     */
    public static function diagrams(): iterable
    {
        foreach (['soldier', 'bishop', 'rook', 'knight', 'paladin', 'guard', 'ballista', 'king'] as $type) {
            foreach (['white', 'black'] as $side) {
                yield "$type $side" => ["pieces/$type-$side.svg"];
            }
        }

        foreach (['blocked-ballista', 'stack', 'unstack'] as $topic) {
            yield $topic => ["$topic.svg"];
        }
    }

    #[DataProvider('diagrams')]
    public function testTheDiagramExistsAndIsASmallBoardSvg(string $file): void
    {
        $path = \dirname(__DIR__, 3).'/public/help/'.$file;

        self::assertFileExists($path);
        self::assertLessThan(self::MAX_BYTES, filesize($path), 'the export prunes unused sprites: keep each diagram small');

        $svg = (string) file_get_contents($path);
        self::assertStringContainsString('class="keres-board"', $svg);
        self::assertStringNotContainsString('<script', $svg);
        self::assertTrue(false !== simplexml_load_string($svg), 'well-formed XML');
    }
}
