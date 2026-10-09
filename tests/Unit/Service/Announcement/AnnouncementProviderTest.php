<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Announcement;

use App\Service\Announcement\AnnouncementProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class AnnouncementProviderTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        yield 'en' => ['en'];
        yield 'fr' => ['fr'];
    }

    public function testAnnouncementsAreNewestFirstAndTiesKeepDeclaredOrder(): void
    {
        $announcements = $this->provider('en')->latest();

        self::assertCount(\count(AnnouncementProvider::ENTRIES), $announcements);

        $dates = array_map(static fn ($a): string => $a->date->format('Y-m-d'), $announcements);
        $sorted = $dates;
        rsort($sorted);
        self::assertSame($sorted, $dates);

        $sameDay = array_values(array_filter(
            array_keys(AnnouncementProvider::ENTRIES),
            static fn (string $id): bool => AnnouncementProvider::ENTRIES[$id] === $dates[0],
        ));
        self::assertSame($sameDay, \array_slice(array_map(static fn ($a): string => $a->id, $announcements), 0, \count($sameDay)));
    }

    public function testLimitKeepsTheNewest(): void
    {
        $all = $this->provider('en')->latest();
        $limited = $this->provider('en')->latest(3);

        self::assertEquals(\array_slice($all, 0, 3), $limited);
    }

    #[DataProvider('locales')]
    public function testEveryEntryHasATitleAndABodyInEveryLocale(string $locale): void
    {
        $translator = $this->translator($locale);

        foreach (array_keys(AnnouncementProvider::ENTRIES) as $id) {
            foreach ([AnnouncementProvider::titleKey($id), AnnouncementProvider::bodyKey($id)] as $key) {
                self::assertTrue(
                    $translator->getCatalogue($locale)->defines($key, AnnouncementProvider::DOMAIN.'+intl-icu'),
                    \sprintf('"%s" is missing from announcements+intl-icu.%s.yaml.', $key, $locale),
                );
            }
        }
    }

    private function provider(string $locale): AnnouncementProvider
    {
        return new AnnouncementProvider($this->translator($locale));
    }

    private function translator(string $locale): Translator
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', \dirname(__DIR__, 4).'/translations/announcements+intl-icu.'.$locale.'.yaml', $locale, 'announcements+intl-icu');

        return $translator;
    }
}
