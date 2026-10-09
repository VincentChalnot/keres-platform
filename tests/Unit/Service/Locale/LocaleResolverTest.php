<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Locale;

use App\Entity\User;
use App\Service\Locale\LocaleResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class LocaleResolverTest extends TestCase
{
    private LocaleResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new LocaleResolver(['en', 'fr'], 'en');
    }

    public function testSavedUserLocaleWinsOverCookieAndBrowser(): void
    {
        $user = new User('player@example.com');
        $user->setLocale('fr');
        $request = $this->request(cookie: 'en', acceptLanguage: 'en-US,en;q=0.9');

        self::assertSame('fr', $this->resolver->resolve($request, $user));
    }

    public function testUserWithoutSavedLocaleFollowsCookieThenBrowser(): void
    {
        $user = new User('player@example.com');

        self::assertSame('fr', $this->resolver->resolve($this->request(cookie: 'fr', acceptLanguage: 'en'), $user));
        self::assertSame('fr', $this->resolver->resolve($this->request(acceptLanguage: 'fr-CA,fr;q=0.9,en;q=0.5'), $user));
        self::assertSame('fr', $this->resolver->resolve($this->request(acceptLanguage: 'fr-CA,fr;q=0.9,en;q=0.5'), null));
    }

    public function testUnsupportedValuesFallBackToTheDefault(): void
    {
        $user = new User('player@example.com');
        $user->setLocale('de');

        self::assertSame('en', $this->resolver->resolve($this->request(cookie: 'de', acceptLanguage: 'de-DE,de;q=0.9'), $user));
        self::assertSame('en', $this->resolver->resolve($this->request(), null));
    }

    public function testBrowserPreferenceIsPickedAmongEnabledLocalesOnly(): void
    {
        self::assertSame('fr', $this->resolver->resolveFromBrowser($this->request(acceptLanguage: 'de,fr;q=0.8,en;q=0.5')));
        self::assertSame('en', $this->resolver->resolveFromBrowser($this->request(acceptLanguage: 'en-GB')));
    }

    private function request(?string $cookie = null, ?string $acceptLanguage = null): Request
    {
        $request = new Request(cookies: null === $cookie ? [] : [LocaleResolver::COOKIE_NAME => $cookie]);

        if (null !== $acceptLanguage) {
            $request->headers->set('Accept-Language', $acceptLanguage);
        }

        return $request;
    }
}
