<?php

declare(strict_types=1);

namespace App\Tests\Functional\Action;

use App\Entity\Game;
use App\Entity\User;
use App\Model\ColorPreference;
use App\Model\GameEndReason;
use App\Model\OpponentType;
use App\Repository\GameRepository;
use App\Service\GameFactory;
use App\Tests\Functional\DatabaseTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * D16 of `DECISIONS.md`: guests play level 1 only, a player has one bot game at a time, and the AI's
 * replies are rate-limited per user without touching the game when refused.
 */
final class BotGameLimitsTest extends DatabaseTestCase
{
    public function testAGuestCannotStartAGameAboveLevelOne(): void
    {
        $client = static::createClient();

        $client->request('POST', '/play/new', ['local_game' => $this->form(2)], [], $this->sameOrigin());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sign in to play against this level.', (string) $client->getResponse()->getContent());

        $client->request('POST', '/play/new', ['local_game' => $this->form(1)], [], $this->sameOrigin());
        self::assertResponseRedirects();
        self::assertStringContainsString('/play/guest', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testASecondBotGameIsRefusedAndTheRunningOneIsOffered(): void
    {
        $client = static::createClient();
        $user = $this->createUser('player@example.com');
        $client->loginUser($user);
        $running = $this->casualGame($user, 2);

        $client->request('POST', '/play/new', ['local_game' => $this->form(3)], [], $this->sameOrigin());

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('You already have a game against Bot 2', $content);
        self::assertStringContainsString('href="/play/'.$running->getUuid()->toRfc4122().'"', $content);
        self::assertSame(1, $this->runningBotGames($user), 'nothing was created');
        self::assertFalse($running->isGameOver());
    }

    public function testReplaceCurrentEndsTheRunningGameThenCreatesTheNewOne(): void
    {
        $client = static::createClient();
        $user = $this->createUser('player@example.com');
        $client->loginUser($user);
        $running = $this->casualGame($user, 2);

        $client->request('POST', '/play/new', ['local_game' => $this->form(3) + ['replaceCurrent' => '']], [], $this->sameOrigin());

        self::assertResponseRedirects();
        self::assertTrue($running->isGameOver());
        self::assertSame(GameEndReason::ABORTED, $running->getEndReason(), 'a game without moves is aborted, not lost');
        $current = static::getContainer()->get(GameRepository::class)->findInProgressBotGameForUser($user);
        self::assertNotNull($current);
        self::assertSame(3, $current->getAiLevel());
        self::assertStringContainsString($current->getUuid()->toRfc4122(), (string) $client->getResponse()->headers->get('Location'));
    }

    public function testHotSeatIsNotLimited(): void
    {
        $client = static::createClient();
        $user = $this->createUser('player@example.com');
        $client->loginUser($user);
        $this->casualGame($user, 2);

        $form = ['opponentType' => '1'] + $this->form(1); // choices are indexed in declaration order: AI, then hot-seat
        $client->request('POST', '/play/new', ['local_game' => $form], [], $this->sameOrigin());

        self::assertResponseRedirects();
        self::assertSame(1, $this->runningBotGames($user));
    }

    public function testARefusedAiBudgetLeavesTheGameUntouched(): void
    {
        $client = static::createClient();
        $user = $this->createUser('player@example.com');
        $client->loginUser($user);
        $game = $this->casualGame($user, 2);

        /** @var RateLimiterFactory $limiter */
        $limiter = static::getContainer()->get('limiter.user_ai');
        $limiter->create($user->getId()->toRfc4122())->consume(40);

        $client->request('POST', '/play/'.$game->getUuid()->toRfc4122().'/move', [], [], $this->sameOrigin(), "\x00\x00");

        self::assertResponseStatusCodeSame(429);
        self::assertSame('ai_rate_limited', json_decode((string) $client->getResponse()->getContent(), true)['code']);
        $this->em()->refresh($game);
        self::assertCount(0, $game->getGameMoves());
    }

    public function testOpeningAGameWhereTheAiMovesFirstConsumesTheBudgetAndRefusalDispatchesNothing(): void
    {
        $client = static::createClient();
        $user = $this->createUser('player@example.com');
        $client->loginUser($user);
        $game = static::getContainer()->get(GameFactory::class)->createAiOrHotseatGame($user, OpponentType::AI, ColorPreference::BLACK, 2);
        $this->em()->persist($game);
        $this->em()->flush();
        $sent = static fn (): int => \count(static::getContainer()->get('messenger.transport.async')->getSent());

        $client->request('GET', '/play/'.$game->getUuid()->toRfc4122(), [], [], $this->sameOrigin());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $sent(), 'the AI plays the first move');

        /** @var RateLimiterFactory $limiter */
        $limiter = static::getContainer()->get('limiter.user_ai');
        $limiter->create($user->getId()->toRfc4122())->consume(40);

        $client->request('GET', '/play/'.$game->getUuid()->toRfc4122(), [], [], $this->sameOrigin());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $sent(), 'a refused budget dispatches nothing');
        self::assertStringContainsString('The bot needs a breather', (string) $client->getResponse()->getContent());
    }

    /** @return array<string, string> */
    private function form(int $level): array
    {
        return [
            'playerSide' => 'white',
            'opponentType' => '0',
            'aiLevel' => (string) $level,
            'cadence' => 'rapid',
            '_token' => 'csrf-token',
        ];
    }

    private function casualGame(User $user, int $level): Game
    {
        $game = static::getContainer()->get(GameFactory::class)->createAiOrHotseatGame($user, OpponentType::AI, ColorPreference::WHITE, $level);
        $this->em()->persist($game);
        $this->em()->flush();

        return $game;
    }

    private function runningBotGames(User $user): int
    {
        return (int) $this->em()->createQuery(
            'SELECT COUNT(DISTINCT g.id) FROM '.Game::class.' g JOIN g.players p WHERE p.user = :u AND g.aiLevel IS NOT NULL AND g.gameOverAt IS NULL'
        )->setParameter('u', $user)->getSingleScalarResult();
    }
}
