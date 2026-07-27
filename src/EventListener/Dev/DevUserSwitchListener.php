<?php

declare(strict_types=1);

namespace App\EventListener\Dev;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UsernameGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Dev-only testing aid, **not a feature**: a `?_as=<email>` query parameter
 * overrides the acting user for the current request only.
 *
 * Exists because a single Playwright-controlled Chromium instance shares one
 * cookie jar across tabs, so two tabs cannot hold two independent
 * session-authenticated users the normal way (`/dev/login`). `_as` sidesteps
 * the shared session entirely: the override is applied to the in-memory
 * token storage after the firewall has resolved the real session user, and
 * is unconditionally reverted before the response leaves, so
 * `ContextListener` never persists it - the shared session cookie is
 * completely untouched by requests carrying `_as`.
 *
 * Inert outside `kernel.environment == dev`, exactly like
 * `DevLoginAuthenticator`. Requires an already-authenticated session (any
 * dev user) so `access_control`'s `ROLE_USER` rules keep passing; only the
 * acting *identity* seen by controllers and voters changes.
 */
final class DevUserSwitchListener
{
    private const string QUERY_PARAM = '_as';
    private const string ORIGINAL_TOKEN_ATTRIBUTE = '_dev_as_original_token';
    private const string ACTIVE_ATTRIBUTE = '_dev_as_active';
    private const string ALLOWED_ENVIRONMENT = 'dev';
    private const string FIREWALL_NAME = 'main';

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UserRepository $userRepository,
        private readonly UsernameGenerator $usernameGenerator,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $environment,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: -10)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (self::ALLOWED_ENVIRONMENT !== $this->environment || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $email = $request->query->get(self::QUERY_PARAM);

        if (!\is_string($email) || '' === trim($email)) {
            return;
        }

        $user = $this->userRepository->findByEmail($email);

        if (null === $user) {
            $user = new User($email);
            $user->setDisplayName($email);
            $user->setUsername($this->usernameGenerator->generate($email, $email));
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }

        $request->attributes->set(self::ORIGINAL_TOKEN_ATTRIBUTE, $this->tokenStorage->getToken());
        $request->attributes->set(self::ACTIVE_ATTRIBUTE, true);
        $this->tokenStorage->setToken(new UsernamePasswordToken($user, self::FIREWALL_NAME, $user->getRoles()));
    }

    /** Priority above every other kernel.response listener (incl. ContextListener's, added at 0) so the swap never reaches the session. */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: 1024)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (true !== $request->attributes->get(self::ACTIVE_ATTRIBUTE)) {
            return;
        }

        $original = $request->attributes->get(self::ORIGINAL_TOKEN_ATTRIBUTE);
        $this->tokenStorage->setToken($original instanceof TokenInterface ? $original : null);
    }
}
