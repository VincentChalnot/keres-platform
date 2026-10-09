<?php

declare(strict_types=1);

namespace App\Service\Locale;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Which locale an outgoing email is written in. Emails are rendered in the
 * recipient's language, never in whatever locale the current request or
 * worker happens to have.
 */
final readonly class EmailLocale
{
    public function __construct(
        private LocaleResolver $localeResolver,
        private TranslatorInterface $translator,
        #[Autowire('%kernel.default_locale%')]
        private string $defaultLocale,
    ) {
    }

    /** The account's saved locale when it is still an enabled one, otherwise the default locale. */
    public function forUser(User $user): string
    {
        $locale = $user->getLocale();

        return null !== $locale && $this->localeResolver->isSupported($locale) ? $locale : $this->defaultLocale;
    }

    /**
     * For recipients without an account (waitlist signup): the locale of the
     * request that queues the mail - the visitor was just reading the site in it.
     */
    public function forCurrentRequest(): string
    {
        $locale = $this->translator->getLocale();

        return $this->localeResolver->isSupported($locale) ? $locale : $this->defaultLocale;
    }
}
