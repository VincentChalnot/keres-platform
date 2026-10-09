<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\Locale\LocaleResolver;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A new account starts in the language its first request asked for (language
 * cookie, then Accept-Language), whichever sign-up path created it (OIDC,
 * e-mail/password, dev login). Created outside a request (console, bots) it
 * stays NULL and follows the default locale.
 *
 * `resolveAnonymous()` rather than the request's locale: accounts are created
 * inside the firewall, before `LocaleListener` has run.
 */
#[AsEntityListener(event: Events::prePersist, entity: User::class)]
final readonly class NewUserLocaleListener
{
    public function __construct(
        private LocaleResolver $resolver,
        private RequestStack $requestStack,
    ) {
    }

    public function __invoke(User $user): void
    {
        $request = $this->requestStack->getMainRequest();

        if (null === $request || null !== $user->getLocale()) {
            return;
        }

        $user->setLocale($this->resolver->resolveAnonymous($request));
    }
}
