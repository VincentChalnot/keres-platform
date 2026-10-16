<?php

declare(strict_types=1);

namespace App\Tests\Functional\Action;

use App\Engine\EngineApi;
use App\Entity\Game;
use App\Entity\Move;
use App\Entity\User;
use App\Model\BoardData;
use App\Model\ColorPreference;
use App\Model\EvaluatedMove;
use App\Model\MoveData;
use App\Model\MovesData;
use App\Model\OpponentType;
use App\Model\TimeControl;
use App\Service\GameFactory;
use App\Tests\Functional\DatabaseTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * The coach's hint (Phase 6 of `docs/onboarding-plan.md`): `POST /api/games/{uuid}/hint` and `POST /api/hint` ask the
 * engine's `/evaluate-moves` once (stubbed here: CI has no engine), answer the best entry, store every returned move on
 * the shared tree with its evaluation, consume the AI budget once and, for a persisted game, mark it assisted.
 */
final class HintTest extends DatabaseTestCase
{
    public function testAHintReturnsTheBestMoveStoresEveryMoveAndMarksTheGameAssisted(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // keeps the stubbed engine and the entity manager across requests
        $this->stubEngine();
        $user = $this->createUser('player@example.com');
        $client->loginUser($user);
        $game = $this->casualGame($user, ColorPreference::WHITE);
        $before = $this->edgeCount();

        $client->request('POST', '/api/games/'.$game->getUuid()->toRfc4122().'/hint', [], [], $this->sameOrigin());

        self::assertResponseIsSuccessful();
        self::assertSame(0x0A0B, json_decode((string) $client->getResponse()->getContent(), true)['data']['move'], 'the first entry of the best-first list');
        self::assertSame($before + 3, $this->edgeCount(), 'all three evaluated moves, the played one or not, are edges');

        $evaluations = $this->em()->createQuery('SELECT m.evaluation FROM '.Move::class.' m ORDER BY m.evaluation DESC')->getSingleColumnResult();
        self::assertSame([40, -10, -250], \array_slice($evaluations, 0, 3));
        self::assertSame(0, (int) $this->em()->createQuery('SELECT COUNT(gm) FROM '.\App\Entity\GameMove::class.' gm')->getSingleScalarResult(), 'no GameMove for those edges');
        self::assertTrue($this->em()->find(Game::class, $game->getId())->isAssisted());

        // A second hint on the same position keeps the evaluations (first write wins) and creates nothing new.
        $client->request('POST', '/api/games/'.$game->getUuid()->toRfc4122().'/hint', [], [], $this->sameOrigin());
        self::assertSame($before + 3, $this->edgeCount());
    }

    public function testASpentAiBudgetRefusesTheHintAndLeavesTheGameUnassisted(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // keeps the stubbed engine and the entity manager across requests
        $this->stubEngine();
        $user = $this->createUser('player@example.com');
        $client->loginUser($user);
        $game = $this->casualGame($user, ColorPreference::WHITE);

        /** @var RateLimiterFactory $limiter */
        $limiter = static::getContainer()->get('limiter.user_ai');
        $limiter->create($user->getId()->toRfc4122())->consume(40);

        $client->request('POST', '/api/games/'.$game->getUuid()->toRfc4122().'/hint', [], [], $this->sameOrigin());

        self::assertResponseStatusCodeSame(429);
        self::assertSame('ai_rate_limited', json_decode((string) $client->getResponse()->getContent(), true)['error']['code']);
        self::assertFalse($this->em()->find(Game::class, $game->getId())->isAssisted());
    }

    public function testOnlyTheParticipantOnTheirOwnTurnOfACasualBotGame(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->stubEngine();
        $user = $this->createUser('player@example.com');
        $other = $this->createUser('other@example.com');
        $bot = $this->createUser('bot@example.com');
        $factory = static::getContainer()->get(GameFactory::class);

        // Every fixture is built before the first request (the entity manager is reset after each one).
        // Black against the AI: it is White's turn, the AI's.
        $blackGame = $this->casualGame($user, ColorPreference::BLACK);
        // A hot-seat game is not a bot game.
        $hotSeat = $factory->createAiOrHotseatGame($user, OpponentType::HOTSEAT, ColorPreference::WHITE);
        // A rated, clocked game against a bot account: never.
        $rated = $factory->createBotGame($user, $bot, 3, ColorPreference::WHITE, TimeControl::realtime(420, 5));
        $own = $this->casualGame($user, ColorPreference::WHITE);
        $this->em()->persist($hotSeat);
        $this->em()->persist($rated);
        $this->em()->flush();

        $client->loginUser($user);

        foreach ([$blackGame, $hotSeat, $rated] as $game) {
            $client->request('POST', '/api/games/'.$game->getUuid()->toRfc4122().'/hint', [], [], $this->sameOrigin());
            self::assertResponseStatusCodeSame(403);
        }

        // Somebody else's game: not found for them.
        $client->loginUser($other);
        $client->request('POST', '/api/games/'.$own->getUuid()->toRfc4122().'/hint', [], [], $this->sameOrigin());
        self::assertResponseStatusCodeSame(404);
    }

    public function testAGuestHintIsRateLimitedPerIpAndFeedsTheTree(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // keeps the stubbed engine and the entity manager across requests
        $this->stubEngine();
        $before = $this->edgeCount();
        // A fresh address each run: the budget of an IP lives in the (persistent) rate-limiter cache.
        $ip = '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
        $server = $this->sameOrigin() + ['REMOTE_ADDR' => $ip];

        $client->request('POST', '/api/hint', [], [], $server, '');

        self::assertResponseIsSuccessful();
        self::assertSame(0x0A0B, json_decode((string) $client->getResponse()->getContent(), true)['data']['move']);
        self::assertSame($before + 3, $this->edgeCount());

        $client->request('POST', '/api/hint', [], [], $server, "\x01");
        self::assertResponseStatusCodeSame(422, 'a move list is made of two-byte moves');

        /** @var RateLimiterFactory $limiter */
        $limiter = static::getContainer()->get('limiter.guest_ai');

        while ($limiter->create($ip)->consume(1)->isAccepted()) {
            // spend the whole budget of this IP
        }
        $client->request('POST', '/api/hint', [], [], $server, '');
        self::assertResponseStatusCodeSame(429);
    }

    private function stubEngine(): void
    {
        $engine = $this->createMock(EngineApi::class);
        $engine->method('evaluateMoves')->willReturn([
            new EvaluatedMove(new MoveData(pack('v', 0x0A0B)), 40),
            new EvaluatedMove(new MoveData(pack('v', 0x0A0C)), -10),
            new EvaluatedMove(new MoveData(pack('v', 0x0A0D)), -250),
        ]);
        // A distinct, deterministic board per line, standing in for the engine's replay.
        $engine->method('replayMoves')->willReturnCallback(static fn (MovesData $line): BoardData => new BoardData(str_pad(md5($line->toBinary(), true), 81, "\0")."\x80\x00"));
        static::getContainer()->set(EngineApi::class, $engine);
    }

    private function casualGame(User $user, ColorPreference $color): Game
    {
        $game = static::getContainer()->get(GameFactory::class)->createAiOrHotseatGame($user, OpponentType::AI, $color, 2);
        $this->em()->persist($game);
        $this->em()->flush();

        return $game;
    }

    private function edgeCount(): int
    {
        return (int) $this->em()->createQuery('SELECT COUNT(m) FROM '.Move::class.' m')->getSingleScalarResult();
    }
}
