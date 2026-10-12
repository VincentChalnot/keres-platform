<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service\BotTournament;

use App\Entity\Game;
use App\Entity\User;
use App\Entity\UserRating;
use App\Model\PieceColor;
use App\Model\SpeedCategory;
use App\Model\TimeControl;
use App\Service\BotTournament\BotAccounts;
use App\Service\BotTournament\BotTournamentReset;
use App\Service\GameFactory;
use App\Tests\Functional\DatabaseTestCase;

/**
 * `app:bot-tournament --reset` wipes what the bots played against each other and their ratings, and nothing else:
 * their games against humans survive.
 */
final class BotTournamentResetTest extends DatabaseTestCase
{
    public function testResetRemovesBotVersusBotGamesAndBotRatingsOnly(): void
    {
        static::bootKernel();
        $ids = static::getContainer()->get(BotAccounts::class)->ensure([1, 2]);
        $bots = [];

        foreach ($ids as $level => $id) {
            $bots[$level] = $this->em()->find(User::class, $id);
        }
        $human = $this->createUser('human@example.com');

        $factory = static::getContainer()->get(GameFactory::class);
        $botGame = $factory->createMultiplayerGame($bots[1], $bots[2], PieceColor::WHITE, TimeControl::realtime(900, 5), true);
        $humanGame = $factory->createMultiplayerGame($human, $bots[1], PieceColor::WHITE, TimeControl::realtime(900, 5), true);
        $this->em()->persist($botGame);
        $this->em()->persist($humanGame);
        $this->em()->persist(new UserRating($bots[1], SpeedCategory::RAPID));
        $this->em()->persist(new UserRating($human, SpeedCategory::RAPID));
        $this->em()->flush();

        $reset = static::getContainer()->get(BotTournamentReset::class);
        self::assertSame(['games' => 1, 'ratings' => 1], $reset->counts($ids));
        self::assertSame(['games' => 1, 'ratings' => 1], $reset->reset($ids));

        $this->em()->clear();
        self::assertNotNull($this->em()->find(Game::class, $botGame->getId())->getDeletedAt());
        self::assertNull($this->em()->find(Game::class, $humanGame->getId())->getDeletedAt());
        self::assertCount(1, $this->em()->getRepository(UserRating::class)->findAll(), 'the human keeps their rating');
    }
}
