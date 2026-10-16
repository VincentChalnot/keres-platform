<?php

declare(strict_types=1);

namespace App\Tests\Functional\Action;

use App\Entity\Game;
use App\Model\ColorPreference;
use App\Model\OpponentType;
use App\Repository\GameRepository;
use App\Service\GameFactory;
use App\Tests\Functional\DatabaseTestCase;

/**
 * D16 of `DECISIONS.md`: `/` is the home page, the dashboard and `/play` redirect to it, `/lobby` is
 * online play for signed-in players only.
 */
final class HomeTest extends DatabaseTestCase
{
    public function testAnonymousHomeIsTheHero(): void
    {
        $client = static::createClient();

        $client->request('GET', '/', [], [], $this->sameOrigin());

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Play now, no account needed', $content);
        self::assertStringContainsString('Play with a friend on this device', $content);
        self::assertStringNotContainsString('Continue', $content);
    }

    public function testSignedInHomeGreetsByNameNeverByEmail(): void
    {
        $client = static::createClient();
        $user = $this->createUser('secret.address@example.com');
        $user->setDisplayName('secret.address@example.com'); // the dev/OIDC sign-up copies the email there
        $user->setUsername('ada');
        $this->em()->flush();
        $client->loginUser($user);

        $client->request('GET', '/', [], [], $this->sameOrigin());

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Welcome, ada', $content);
        self::assertStringNotContainsString('secret.address@example.com', $content);
        self::assertStringNotContainsString('Your stats', $content, 'stats stay hidden until a game is finished');
    }

    public function testTheRunningBotGameIsOfferedWithItsResignation(): void
    {
        $client = static::createClient();
        $user = $this->createUser('player@example.com');
        $game = static::getContainer()->get(GameFactory::class)->createAiOrHotseatGame($user, OpponentType::AI, ColorPreference::WHITE, 2);
        $this->em()->persist($game);
        $this->em()->flush();
        $client->loginUser($user);

        $client->request('GET', '/', [], [], $this->sameOrigin());
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Game against Bot 2', $content);
        self::assertStringContainsString('/play/bot-game/resign', $content);

        $client->request('POST', '/play/bot-game/resign', ['_token' => 'csrf-token'], [], $this->sameOrigin());
        self::assertResponseRedirects('/');
        self::assertTrue($this->em()->find(Game::class, $game->getId())->isGameOver());
        self::assertNull(static::getContainer()->get(GameRepository::class)->findInProgressBotGameForUser($user));
    }

    public function testOldEntryPointsRedirectHome(): void
    {
        $client = static::createClient();

        $client->request('GET', '/dashboard');
        self::assertResponseStatusCodeSame(301);
        self::assertResponseHeaderSame('Location', '/');

        $client->request('GET', '/play');
        self::assertResponseRedirects('/');
    }

    public function testTheLobbyIsForSignedInPlayersOnly(): void
    {
        $client = static::createClient();

        $client->request('GET', '/lobby', [], [], $this->sameOrigin());
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));

        $client->loginUser($this->createUser('player@example.com'));
        $client->request('GET', '/lobby', [], [], $this->sameOrigin());
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Players looking for a game', $content);
        self::assertStringContainsString('data-quick-pair', $content);
        self::assertStringNotContainsString('Beat the next bot', $content);
    }
}
