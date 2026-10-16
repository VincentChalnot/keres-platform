<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\Game;
use App\Entity\User;
use App\Model\ColorPreference;
use App\Model\GameEndReason;
use App\Model\OpponentType;
use App\Model\TimeControl;
use App\Service\GameFactory;
use App\Tests\Functional\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class AbortExtraBotGamesCommandTest extends DatabaseTestCase
{
    public function testKeepsTheMostRecentlyPlayedGameAndAbortsTheRest(): void
    {
        $kernel = static::bootKernel();
        $human = $this->createUser('human@example.com');

        $oldCasual = $this->casual($human);
        $newerCasual = $this->casual($human);
        $rated = $this->rated($human);
        // The oldest game is the one that was played last: it is the one to keep, not the newest created.
        $this->addPlies($oldCasual, 2);

        $single = $this->casual($this->createUser('single@example.com'));
        $hotSeat = static::getContainer()->get(GameFactory::class)->createAiOrHotseatGame($human, OpponentType::HOTSEAT, ColorPreference::WHITE);
        $this->em()->persist($hotSeat);
        $this->em()->flush();

        $tester = new CommandTester((new Application($kernel))->find('app:bot-games:abort-extra'));

        $tester->execute(['--dry-run' => true]);
        self::assertStringContainsString('[dry run] 1 user(s) with several bot games, 1 game(s) kept, 2 game(s) would be aborted.', $this->flat($tester));
        self::assertFalse($newerCasual->isGameOver(), 'a dry run changes nothing');

        $tester->execute([]);
        self::assertStringContainsString('1 user(s) with several bot games, 1 game(s) kept, 2 game(s) aborted.', $this->flat($tester));

        self::assertFalse($oldCasual->isGameOver());

        foreach ([$newerCasual, $rated] as $aborted) {
            self::assertTrue($aborted->isGameOver());
            self::assertSame(GameEndReason::ABORTED, $aborted->getEndReason());
        }
        self::assertFalse($single->isGameOver(), 'a user with one bot game is left alone');
        self::assertFalse($hotSeat->isGameOver(), 'hot-seat games are not bot games');

        $tester->execute([]);
        self::assertStringContainsString('0 user(s) with several bot games', $this->flat($tester));
    }

    /** SymfonyStyle wraps its blocks: compare the text on one line. */
    private function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function casual(User $human): Game
    {
        $game = static::getContainer()->get(GameFactory::class)->createAiOrHotseatGame($human, OpponentType::AI, ColorPreference::WHITE, 2);
        $this->em()->persist($game);
        $this->em()->flush();

        return $game;
    }

    private function rated(User $human): Game
    {
        $bot = $this->createUser('bot-'.bin2hex(random_bytes(3)).'@example.com');
        $game = static::getContainer()->get(GameFactory::class)->createBotGame($human, $bot, 3, ColorPreference::WHITE, TimeControl::realtime(420, 5));
        $this->em()->persist($game);
        $this->em()->flush();

        return $game;
    }
}
