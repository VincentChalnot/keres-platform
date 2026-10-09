<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Entity\User;
use App\EventListener\SentryUserContextListener;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

final class SentryUserContextListenerTest extends TestCase
{
    public function testAttachesOnlyTheUserId(): void
    {
        $user = new User('player@example.com');
        $hub = new Hub();

        $this->listener($hub, $user)->onKernelException($this->event());

        $bag = $this->userOf($hub);
        self::assertNotNull($bag);
        self::assertSame((string) $user->getId(), $bag->getId());
        self::assertNull($bag->getEmail());
        self::assertNull($bag->getUsername());
        self::assertNull($bag->getIpAddress());
    }

    public function testAnonymousVisitorGetsNoUserContext(): void
    {
        $hub = new Hub();

        $this->listener($hub, null)->onKernelException($this->event());

        self::assertNull($this->userOf($hub));
    }

    public function testForeignUserTypeIsIgnored(): void
    {
        $hub = new Hub();

        $this->listener($hub, new InMemoryUser('x', null))->onKernelException($this->event());

        self::assertNull($this->userOf($hub));
    }

    private function listener(Hub $hub, ?UserInterface $user): SentryUserContextListener
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new SentryUserContextListener($hub, $security);
    }

    private function event(): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('boom'),
        );
    }

    private function userOf(Hub $hub): ?UserDataBag
    {
        $event = Event::createEvent();
        $hub->configureScope(static function (Scope $scope) use (&$event): void {
            $event = $scope->applyToEvent($event) ?? $event;
        });

        return $event->getUser();
    }
}
