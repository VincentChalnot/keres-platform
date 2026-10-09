<?php

declare(strict_types=1);

namespace App\Tests\Functional\Translation;

use App\Service\Locale\FrontendCatalogue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

/**
 * The TypeScript client has no compile-time key checking, so this is it: every
 * literal key passed to `t()` / `hasTranslation()` under assets/typescript/src
 * must exist in the catalogue the server exports to the page (the `frontend`
 * and `frontend_<area>` translation domains, see FrontendCatalogue), in every
 * locale. Also pins the export itself (area prefixing, fallback, HTML-safe JSON).
 */
final class FrontendCatalogueTest extends KernelTestCase
{
    private const string TS_ROOT = __DIR__.'/../../../assets/typescript/src';

    /** TypeScript that is not part of the translated client. */
    private const array EXCLUDED = ['i18n/', 'admin/', 'admin.ts', 'views/ThreeJSBoardView.ts'];

    public function testEveryKeyUsedByTypeScriptIsInTheCatalogueOfEveryLocale(): void
    {
        self::bootKernel();
        $catalogue = self::getContainer()->get(FrontendCatalogue::class);
        self::assertInstanceOf(FrontendCatalogue::class, $catalogue);

        $missing = [];

        foreach (['en', 'fr'] as $locale) {
            $keys = array_keys($catalogue->messages($locale));

            foreach ($this->keysUsedByTypeScript() as $key => $files) {
                $exists = str_contains($key, '${')
                    ? [] !== array_filter($keys, static fn (string $known): bool => str_starts_with($known, substr($key, 0, (int) strpos($key, '${'))))
                    : \in_array($key, $keys, true);

                if (!$exists) {
                    $missing[] = \sprintf('[%s] %s (used in %s)', $locale, $key, implode(', ', $files));
                }
            }
        }

        self::assertSame([], $missing, "Keys used in TypeScript but absent from the frontend catalogue:\n - ".implode("\n - ", $missing));
    }

    public function testAreaDomainsAreExportedUnderTheirPrefixWithFallbackToEnglish(): void
    {
        self::bootKernel();
        $catalogue = self::getContainer()->get(FrontendCatalogue::class);
        self::assertInstanceOf(FrontendCatalogue::class, $catalogue);

        $en = $catalogue->messages('en');
        $fr = $catalogue->messages('fr');

        self::assertSame('Cancel', $en['common.cancel']);
        self::assertSame('Annuler', $fr['common.cancel']);
        self::assertSame(array_keys($en), array_keys($fr), 'both locales export the same keys');

        $prefixes = array_unique(array_map(static fn (string $key): string => explode('.', $key)[0], array_keys($en)));
        self::assertContains('api_error', $prefixes);
    }

    public function testJsonIsSafeToEmbedInAScriptElement(): void
    {
        self::bootKernel();
        $catalogue = self::getContainer()->get(FrontendCatalogue::class);
        self::assertInstanceOf(FrontendCatalogue::class, $catalogue);

        $json = $catalogue->json('fr');

        self::assertStringNotContainsString('</', $json);
        self::assertStringNotContainsString('<!--', $json);
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('fr', $decoded['locale']);
        self::assertIsArray($decoded['messages']);
    }

    /** @return array<string, list<string>> key (template literals keep their `${…}`) => files using it */
    private function keysUsedByTypeScript(): array
    {
        $used = [];

        foreach ((new Finder())->files()->in(self::TS_ROOT)->name('*.ts') as $file) {
            $relative = $file->getRelativePathname();

            foreach (self::EXCLUDED as $excluded) {
                if (str_starts_with($relative, $excluded)) {
                    continue 2;
                }
            }

            preg_match_all('/(?<![\w.$])(?:t|hasTranslation)\(\s*([\'"`])((?:\\\\.|(?!\1).)*)\1/s', $file->getContents(), $matches);

            foreach ($matches[2] as $key) {
                $used[$key][] = $relative;
            }
        }

        ksort($used);

        return array_map(static fn (array $files): array => array_values(array_unique($files)), $used);
    }
}
