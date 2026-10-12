<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Model\TermsOfUse;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Sends a signed-in account that has not accepted the current terms of use (`TermsOfUse::CURRENT_VERSION`) to
 * `/terms/accept` before anything else: accounts created by OIDC (no registration form), accounts that predate the
 * terms, and everybody again when the version changes.
 *
 * Page navigations only (GET, `Accept: text/html`, not XHR): the API and the polling endpoints keep answering, and the
 * user lands back on the page they asked for once they accept. Priority 6: after the firewall (8) and the locale
 * listener (7), so the page is shown in the user's language.
 */
final readonly class TermsAcceptanceListener
{
    /** Routes reachable without having accepted: the acceptance page itself, leaving, and signing in. */
    private const array EXEMPT_ROUTES = ['terms_accept', 'logout', 'locale_switch', 'login', 'oidc_login', 'oidc_login_check', 'dev_login'];

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // Reading the user starts the session: only for requests that carry one.
        if (!$event->isMainRequest() || !$request->isMethod('GET') || $request->isXmlHttpRequest() || !$request->hasPreviousSession()) {
            return;
        }

        $route = (string) $request->attributes->get('_route', '');

        if ('' === $route || str_starts_with($route, '_') || \in_array($route, self::EXEMPT_ROUTES, true)) {
            return;
        }

        if (!str_contains((string) $request->headers->get('Accept', ''), 'text/html')) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof User || $user->hasAcceptedTerms(TermsOfUse::CURRENT_VERSION)) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('terms_accept', [
            'redirect' => $request->getRequestUri(),
        ])));
    }
}
