<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service\Game;

use App\Entity\Game;
use App\Entity\User;
use App\Model\ColorPreference;
use App\Model\GameEndReason;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Model\TimeControl;
use App\Service\Game\BotGameManager;
use App\Service\GameFactory;
use App\Tests\Functional\DatabaseTestCase;

/**
 * "One bot game at a time" (D5) and the colour rule of the one-click bot card (D9).
 */
final class BotGameManagerTest extends DatabaseTestCase
{
    public function testACasualAndARatedBotGameBothCountHotSeatAndFinishedGamesDoNot(): void
    {
        static::bootKernel();
        $human = $this->createUser('human@example.com');
        $manager = static::getContainer()->get(BotGameManager::class);

        self::assertNull($manager->findCurrent($human));

        $hotSeat = $this->casualGame($human, OpponentType::HOTSEAT, null);
        self::assertNull($manager->findCurrent($human), 'hot-seat is not a bot game');

        $finished = $this->casualGame($human, OpponentType::AI, 2);
        $this->em()->wrapInTransaction(static fn () => $manager->abort($finished));
        self::assertNull($manager->findCurrent($human), 'a finished game is not running');

        $casual = $this->casualGame($human, OpponentType::AI, 2);
        self::assertSame($casual, $manager->findCurrent($human));

        $rated = $this->ratedBotGame($human, 3);
        self::assertSame($rated, $manager->findCurrent($human), 'a rated game against a bot counts too (the newest is returned)');
        self::assertNotSame($hotSeat, $manager->findCurrent($human));

        self::assertNull($manager->findCurrent($this->createUser('other@example.com')), 'someone else\'s game does not count');
    }

    public function testRetireAbortsAGameThatWouldNotCountAndResignsOneThatDoes(): void
    {
        static::bootKernel();
        $human = $this->createUser('human@example.com');
        $manager = static::getContainer()->get(BotGameManager::class);

        $fresh = $this->casualGame($human, OpponentType::AI, 2);
        $manager->retire($fresh, $human);
        self::assertTrue($fresh->isGameOver());
        self::assertSame(GameEndReason::ABORTED, $fresh->getEndReason());

        $played = $this->ratedBotGame($human, 3);
        $this->addPlies($played, 4);
        $manager->retire($played, $human);
        self::assertSame(GameEndReason::RESIGNATION, $played->getEndReason());
        $humanColor = $played->getColorsForUser($human)[0];
        self::assertSame(PieceColor::WHITE === $humanColor->opposite(), $played->isWhiteWins(), 'the human is the one who resigned');

        self::assertNull($manager->findCurrent($human));
    }

    private function casualGame(User $human, OpponentType $type, ?int $level): Game
    {
        $game = static::getContainer()->get(GameFactory::class)->createAiOrHotseatGame($human, $type, ColorPreference::WHITE, $level);
        $this->em()->persist($game);
        $this->em()->flush();

        return $game;
    }

    private function ratedBotGame(User $human, int $level): Game
    {
        $bot = $this->createUser('bot'.$level.'@example.com');
        $game = static::getContainer()->get(GameFactory::class)->createBotGame($human, $bot, $level, ColorPreference::WHITE, TimeControl::realtime(420, 5));
        $this->em()->persist($game);
        $this->em()->flush();

        return $game;
    }
}
