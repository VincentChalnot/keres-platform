<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\User;
use App\Repository\UserPreferencesRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

/**
 * Forces logged-in users without a chosen identifier to the preferences page
 * before they can use the rest of the platform.
 *
 * Listens on kernel.controller (not kernel.request) so it runs after both
 * routing and firewall authentication have completed - a kernel.request
 * listener would race the security firewall, which also runs at priority 8.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER, priority: 0)]
class RequireUserIdentifierListener
{
    /**
     * @var string[]
     */
    private const array ALLOWED_ROUTES = [
        'preferences',
        'logout',
        'login',
        'oidc_login',
        'oidc_login_check',
        'dev_login',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly UserPreferencesRepository $userPreferencesRepository,
        private readonly RouterInterface $router,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        if (null === $route || \in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        if (str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return;
        }

        $preferences = $this->userPreferencesRepository->findByUser($user);

        if (null !== $preferences && $preferences->hasIdentifier()) {
            return;
        }

        $redirectUrl = $this->router->generate('preferences');
        $event->setController(static fn (): RedirectResponse => new RedirectResponse($redirectUrl));
    }
}
