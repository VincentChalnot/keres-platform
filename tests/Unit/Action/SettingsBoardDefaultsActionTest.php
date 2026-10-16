<?php

declare(strict_types=1);

namespace App\Tests\Unit\Action;

use App\Action\Settings\SettingsBoardDefaultsAction;
use App\Entity\User;
use App\Entity\UserPreferences;
use App\Model\AnimationSpeed;
use App\Service\UserPreferencesManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SettingsBoardDefaultsActionTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidSpeeds(): iterable
    {
        yield 'unknown value' => ['turbo'];
        yield 'wrong case' => ['FAST'];
        yield 'number' => [1];
        yield 'missing' => [null];
    }

    #[DataProvider('invalidSpeeds')]
    public function testInvalidAnimationSpeedIsRejectedAndNothingIsSaved(mixed $speed): void
    {
        $user = new User('a@example.com');
        $preferences = new UserPreferences($user);

        $response = $this->invoke($user, $preferences, $this->payload($speed));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame(AnimationSpeed::FAST, $preferences->getPieceAnimationSpeed());
    }

    public function testNewPreferencesDefaultToFast(): void
    {
        self::assertSame(AnimationSpeed::FAST, (new UserPreferences(new User('a@example.com')))->getPieceAnimationSpeed());
    }

    public function testValidAnimationSpeedIsSaved(): void
    {
        $user = new User('a@example.com');
        $preferences = new UserPreferences($user);

        $response = $this->invoke($user, $preferences, $this->payload('slow'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(AnimationSpeed::SLOW, $preferences->getPieceAnimationSpeed());
        self::assertSame('slow', json_decode((string) $response->getContent(), true)['data']['animationSpeed']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(mixed $speed): array
    {
        $payload = ['showCoordinates' => true, 'showThreats' => true, 'rotateOpponentPieces' => false, 'coach' => true];

        if (null !== $speed) {
            $payload['animationSpeed'] = $speed;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function invoke(User $user, UserPreferences $preferences, array $payload): Response
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $manager = $this->createMock(UserPreferencesManager::class);
        $manager->method('getOrCreate')->willReturn($preferences);

        $action = new SettingsBoardDefaultsAction($security, $manager, $this->createMock(EntityManagerInterface::class));

        return $action(Request::create('/settings/board/defaults', 'POST', content: json_encode($payload, \JSON_THROW_ON_ERROR)));
    }
}
