<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\Locale\LocaleResolver;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Sets the request locale (and with it the translator, the router context
 * and `<html lang>`) from `LocaleResolver`.
 *
 * Priority 7 puts it right after the firewall (8), the earliest point the
 * signed-in user is known; the framework's own locale listeners (16/15) have
 * already run by then, so it also re-points the translator through
 * `LocaleSwitcher` rather than only calling `Request::setLocale()`.
 *
 * The user is only looked up when the request carries a session cookie:
 * resolving it starts the session, and anonymous requests (health checks,
 * public pages, the Mercure cookie endpoint) must stay session-free.
 */
final readonly class LocaleListener
{
    public function __construct(
        private LocaleResolver $resolver,
        private LocaleSwitcher $localeSwitcher,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $locale = $this->resolver->resolve($request, $this->currentUser($event));

        $request->setLocale($locale);
        $this->localeSwitcher->setLocale($locale);
    }

    /**
     * A signed-in user's saved language is mirrored into the cookie so that it
     * survives logging out (and shows on the login page of the next visit).
     */
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $user = $this->currentUser($event);
        $saved = $user?->getLocale();

        if (!$this->resolver->isSupported($saved) || $request->cookies->get(LocaleResolver::COOKIE_NAME) === $saved) {
            return;
        }

        $event->getResponse()->headers->setCookie(self::cookie($saved, $request->isSecure()));
    }

    public static function cookie(string $locale, bool $secure): Cookie
    {
        return Cookie::create(
            name: LocaleResolver::COOKIE_NAME,
            value: $locale,
            expire: new \DateTimeImmutable('+1 year'),
            path: '/',
            secure: $secure,
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    private function currentUser(RequestEvent|ResponseEvent $event): ?User
    {
        if (!$event->getRequest()->hasPreviousSession()) {
            return null;
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }
}
