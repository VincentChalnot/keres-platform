<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Translation;

use App\Service\Translation\IcuArguments;
use App\Service\Translation\TranslationCatalogueChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The real `translations/` directory must be consistent across the enabled
 * locales: same files, same keys, valid ICU, same arguments per key. This is
 * the PHPUnit twin of `bin/console lint:translations`.
 */
final class TranslationCatalogueTest extends TestCase
{
    private const string TRANSLATIONS_DIR = __DIR__.'/../../../../translations';

    public function testEnglishAndFrenchCataloguesHaveTheSameKeysAndArguments(): void
    {
        $problems = (new TranslationCatalogueChecker(self::TRANSLATIONS_DIR, ['en', 'fr']))->check();

        self::assertSame([], $problems, "Translation catalogues are inconsistent:\n - ".implode("\n - ", $problems));
    }

    public function testCheckerReportsMissingKeysMissingFilesAndArgumentMismatches(): void
    {
        $dir = sys_get_temp_dir().'/keres-translations-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->mkdir($dir);

        try {
            file_put_contents($dir.'/game+intl-icu.en.yaml', "a: One\nb: 'Hello {name}'\nc: '{count, plural, one {# game} other {# games}}'\nonly_en: Only here\n");
            file_put_contents($dir.'/game+intl-icu.fr.yaml', "a: Un\nb: 'Bonjour {user}'\nc: 'Un jeu'\nonly_fr: Seulement ici\n");
            file_put_contents($dir.'/orphan+intl-icu.en.yaml', "x: X\n");

            $problems = (new TranslationCatalogueChecker($dir, ['en', 'fr']))->check();
        } finally {
            $filesystem->remove($dir);
        }

        $report = implode("\n", $problems);

        self::assertStringContainsString('game+intl-icu.fr.yaml: missing key "only_en"', $report);
        self::assertStringContainsString('game+intl-icu.fr.yaml: extra key "only_fr"', $report);
        self::assertStringContainsString('"b" takes arguments [user:simple], en has [name:simple]', $report);
        self::assertStringContainsString('"c" takes arguments [], en has [count:plural]', $report);
        self::assertStringContainsString('orphan+intl-icu.fr.yaml is missing', $report);
    }

    /** @param array<string, string> $expected */
    #[DataProvider('icuMessages')]
    public function testIcuArguments(string $message, array $expected): void
    {
        self::assertSame($expected, IcuArguments::of($message));
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function icuMessages(): iterable
    {
        yield 'plain text' => ['Hello', []];
        yield 'simple argument' => ['Hello {name}', ['name' => 'simple']];
        yield 'typographic apostrophe' => ['L’utilisateur {name}', ['name' => 'simple']];
        yield 'literal apostrophe' => ["It's {name}'s turn", ['name' => 'simple']];
        yield 'quoted brace' => ["Use '{'braces'}' for {name}", ['name' => 'simple']];
        yield 'plural' => ['{count, plural, =0 {No games} one {# game} other {# games}}', ['count' => 'plural']];
        yield 'plural with argument inside' => ['{n, plural, one {{who} moved once} other {{who} moved # times}}', ['n' => 'plural', 'who' => 'simple']];
        yield 'select' => ['{side, select, white {White} black {Black} other {Nobody}}', ['side' => 'select']];
        yield 'number' => ['{n, number} points', ['n' => 'number']];
    }

    #[DataProvider('invalidIcuMessages')]
    public function testInvalidIcuIsRejected(string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);

        IcuArguments::of($message);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIcuMessages(): iterable
    {
        yield 'unclosed argument' => ['Hello {name'];
        yield 'stray closing brace' => ['Hello }'];
        yield 'plural without other' => ['{n, plural, one {# game}}'];
        yield 'unsupported type' => ['{d, date}'];
        yield 'unterminated branch' => ['{n, plural, other {# games}'];
    }
}
