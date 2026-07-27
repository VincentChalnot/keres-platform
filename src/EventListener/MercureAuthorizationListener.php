<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Security\Http\Event\LogoutEvent;

#[AsEventListener(event: KernelEvents::RESPONSE, priority: 0)]
#[AsEventListener(event: LogoutEvent::class, method: 'onLogout')]
final readonly class MercureAuthorizationListener
{
    private const SESSION_KEY = '_mercure_cookie_issued_at';

    public function __construct(
        private Security $security,
        private Authorization $authorization,
        private int $cookieLifetime,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $request = $event->getRequest();
        $issuedAt = (int) $request->getSession()->get(self::SESSION_KEY, 0);

        if ($request->cookies->has('mercureAuthorization')
            && time() - $issuedAt < intdiv($this->cookieLifetime, 2)) {
            return;
        }

        $this->authorization->setCookie($request, ['user/'.$user->getId()->toRfc4122()]);
        $request->getSession()->set(self::SESSION_KEY, time());
    }

    public function onLogout(LogoutEvent $event): void
    {
        $this->authorization->clearCookie($event->getRequest());
    }
}
