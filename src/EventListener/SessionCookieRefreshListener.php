<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Makes the session cookie's expiry slide like the `sessions` row's
 * `sess_lifetime` does.
 *
 * Symfony only re-emits the session cookie when the session id changes
 * (login, logout), so a user active every day would still lose the cookie
 * `cookie_lifetime` after logging in - while the database row keeps being
 * pushed forward on every request. This re-sends the cookie, with a fresh
 * expiry, on every response of a request that used an existing session.
 *
 * Skipped when the id differs from the one the browser sent (new or migrated
 * session): Symfony's own session listener (priority -1000, after this one)
 * sets that cookie itself, and a second Set-Cookie would duplicate it.
 */
final readonly class SessionCookieRefreshListener
{
    /**
     * @param array<string, mixed> $sessionOptions framework.session options (name, cookie_lifetime, cookie_domain...)
     */
    public function __construct(
        #[Autowire('%session.storage.options%')]
        private array $sessionOptions,
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        $lifetime = (int) ($this->sessionOptions['cookie_lifetime'] ?? 0);
        $name = $this->sessionOptions['name'] ?? null;

        if (!$event->isMainRequest() || $lifetime <= 0 || !\is_string($name)) {
            return;
        }

        $request = $event->getRequest();

        // `true`: do not initialise the lazy session just to look at it.
        if (!$request->hasSession(true)) {
            return;
        }

        $session = $request->getSession();
        $sessionId = $request->cookies->get($name);

        if (!$session->isStarted() || $session->isEmpty() || !\is_string($sessionId) || $session->getId() !== $sessionId) {
            return;
        }

        $secure = $this->sessionOptions['cookie_secure'] ?? 'auto';
        $event->getResponse()->headers->setCookie(Cookie::create(
            $name,
            $sessionId,
            time() + $lifetime,
            $this->sessionOptions['cookie_path'] ?? '/',
            $this->sessionOptions['cookie_domain'] ?? null,
            'auto' === $secure ? $request->isSecure() : (bool) $secure,
            (bool) ($this->sessionOptions['cookie_httponly'] ?? true),
            false,
            $this->sessionOptions['cookie_samesite'] ?? Cookie::SAMESITE_LAX,
        ));
    }
}
