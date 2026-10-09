<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Attaches the authenticated user's id - and nothing else (no email,
 * username or IP; `send_default_pii` stays off) - to Sentry events so an
 * error can be traced back to an account.
 *
 * Runs on `kernel.exception`, just before Sentry's own `ErrorListener`
 * (priority 128) captures the exception, rather than on `kernel.request`:
 * resolving the user on every request would start the session for anonymous
 * visitors on the lazy firewall.
 *
 * Prod only, like `SentryBundle` itself (config/bundles.php): no
 * `HubInterface` service exists in the other environments.
 */
#[When(env: 'prod')]
readonly class SentryUserContextListener
{
    public function __construct(
        private HubInterface $hub,
        private Security $security,
    ) {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 129)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return;
        }

        $id = (string) $user->getId();
        $this->hub->configureScope(static function (Scope $scope) use ($id): void {
            $scope->setUser(new UserDataBag($id));
        });
    }
}
