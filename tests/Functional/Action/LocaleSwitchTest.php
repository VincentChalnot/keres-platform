<?php

declare(strict_types=1);

namespace App\Tests\Functional\Action;

use App\Service\Locale\LocaleResolver;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Anonymous language handling end to end: Accept-Language picks the locale
 * (`<html lang>`), the switcher stores a cookie that then wins, and the
 * `redirect` target can never leave the site. No database involved: only
 * anonymous, session-free pages are requested.
 */
final class LocaleSwitchTest extends WebTestCase
{
    public function testAcceptLanguageSelectsTheRenderedLocale(): void
    {
        $client = static::createClient();

        $client->request('GET', '/login', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9,en;q=0.5']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<html lang="fr"', (string) $client->getResponse()->getContent());

        $client->request('GET', '/login', server: ['HTTP_ACCEPT_LANGUAGE' => 'de']);
        self::assertStringContainsString('<html lang="en"', (string) $client->getResponse()->getContent());
    }

    public function testSwitcherSetsTheCookieAndTheCookieBeatsTheBrowser(): void
    {
        $client = static::createClient();

        $client->request('GET', '/locale/fr', ['redirect' => '/login']);
        self::assertResponseRedirects('/login');
        $cookie = $client->getCookieJar()->get(LocaleResolver::COOKIE_NAME);
        self::assertNotNull($cookie);
        self::assertSame('fr', $cookie->getValue());

        $client->request('GET', '/login', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-US']);
        self::assertStringContainsString('<html lang="fr"', (string) $client->getResponse()->getContent());
    }

    public function testRedirectTargetCannotLeaveTheSite(): void
    {
        $client = static::createClient();

        foreach (['//evil.example/x', 'https://evil.example', '/\\evil.example', 'javascript:alert(1)'] as $target) {
            $client->request('GET', '/locale/en', ['redirect' => $target]);
            self::assertResponseRedirects('/', null, \sprintf('redirect=%s must fall back to /', $target));
        }
    }

    public function testUnsupportedLocaleIsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/locale/de');

        self::assertResponseStatusCodeSame(404);
    }
}
