<?php

declare(strict_types=1);

namespace App\Service\Locale;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Which of the enabled locales (`framework.enabled_locales`) a request is
 * served in. Order of precedence:
 *
 *  1. the signed-in user's saved choice (`User::$locale`);
 *  2. the language cookie, set by the language switcher - this is what an
 *     anonymous visitor's choice (and a signed-in user with no saved choice)
 *     is remembered by, without starting a session;
 *  3. the best match for the browser's Accept-Language header;
 *  4. `framework.default_locale`.
 */
final readonly class LocaleResolver
{
    public const string COOKIE_NAME = 'keres_locale';

    /** @param list<string> $enabledLocales */
    public function __construct(
        #[Autowire('%kernel.enabled_locales%')]
        private array $enabledLocales,
        #[Autowire('%kernel.default_locale%')]
        private string $defaultLocale,
    ) {
    }

    /** @return list<string> */
    public function enabledLocales(): array
    {
        return $this->enabledLocales;
    }

    public function isSupported(?string $locale): bool
    {
        return null !== $locale && \in_array($locale, $this->enabledLocales, true);
    }

    public function resolve(Request $request, ?User $user): string
    {
        $saved = $user?->getLocale();

        return $this->isSupported($saved) ? $saved : $this->resolveAnonymous($request);
    }

    /** Cookie, then Accept-Language, then the default: everything but the account's own choice. */
    public function resolveAnonymous(Request $request): string
    {
        $cookie = $request->cookies->get(self::COOKIE_NAME);

        if (\is_string($cookie) && $this->isSupported($cookie)) {
            return $cookie;
        }

        return $this->resolveFromBrowser($request);
    }

    /** Accept-Language only (then the default): what "follow my browser" means. */
    public function resolveFromBrowser(Request $request): string
    {
        $preferred = $request->getPreferredLanguage($this->enabledLocales);

        return $this->isSupported($preferred) ? $preferred : $this->defaultLocale;
    }
}
