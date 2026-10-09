<?php

declare(strict_types=1);

namespace App\Tests\Unit\Action;

use App\Action\Api\GuestEngineMoveAction;
use App\Engine\EngineApi;
use App\Entity\User;
use App\Model\MoveData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class GuestEngineMoveActionTest extends TestCase
{
    /**
     * @return iterable<string, array{int, bool, int}>
     */
    public static function levels(): iterable
    {
        yield 'guest level 4' => [4, false, Response::HTTP_OK];
        yield 'guest level 5 is locked' => [5, false, Response::HTTP_FORBIDDEN];
        yield 'guest level 10 is locked' => [10, false, Response::HTTP_FORBIDDEN];
        yield 'signed-in level 10' => [10, true, Response::HTTP_OK];
        yield 'level 0 is invalid' => [0, true, Response::HTTP_BAD_REQUEST];
        yield 'level 11 is invalid' => [11, true, Response::HTTP_BAD_REQUEST];
    }

    #[DataProvider('levels')]
    public function testLevelGate(int $level, bool $signedIn, int $expectedStatus): void
    {
        $engine = $this->createMock(EngineApi::class);
        $engine->method('aiMove')->willReturn(new MoveData("\x01\x02"));

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($signedIn ? $this->createMock(User::class) : null);

        $limiter = new RateLimiterFactory(['id' => 't', 'policy' => 'no_limit'], new InMemoryStorage());
        $action = new GuestEngineMoveAction($engine, $limiter, $security);

        $response = $action(Request::create('/api/engine-move-game?level='.$level, 'POST'));

        self::assertSame($expectedStatus, $response->getStatusCode());
    }
}
