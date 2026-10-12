<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service\Game;

use App\Entity\User;
use App\Entity\UserRating;
use App\Model\ColorPreference;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Model\SpeedCategory;
use App\Model\TimeControl;
use App\Repository\UserRatingRepository;
use App\Service\BotTournament\BotAccounts;
use App\Service\Game\GameLifecycleManager;
use App\Service\GameFactory;
use App\Tests\Functional\DatabaseTestCase;

/**
 * A rated game against a bot account is an ordinary rated multiplayer game: both sides get a rating, and the bots - the
 * ladder players climb - never show in the leaderboard.
 */
final class RatedBotGameTest extends DatabaseTestCase
{
    public function testFinishedGameRatesBothSidesAndTheBotStaysOutOfTheLeaderboard(): void
    {
        static::bootKernel();
        $botId = static::getContainer()->get(BotAccounts::class)->ensure([3])[3];
        $bot = $this->em()->find(User::class, $botId);
        $human = $this->createUser('human@example.com');

        $game = static::getContainer()->get(GameFactory::class)->createBotGame($human, $bot, 3, ColorPreference::WHITE, TimeControl::realtime(420, 5));
        $this->em()->persist($game);
        $this->em()->flush();
        $this->addPlies($game, 4);

        self::assertTrue($game->isBotGame());
        self::assertSame(OpponentType::MULTIPLAYER, $game->getOpponentType());
        self::assertTrue($game->isRated());
        self::assertSame(3, $game->getAiLevel());

        // Like every caller: the rating update locks rows, so it runs inside the finishing transaction
        $this->em()->wrapInTransaction(static fn () => static::getContainer()->get(GameLifecycleManager::class)->resign($game, PieceColor::WHITE));

        self::assertNotNull($game->getPlayer(PieceColor::WHITE)->getRatingAfter(), 'the human is rated');
        self::assertNotNull($game->getPlayer(PieceColor::BLACK)->getRatingAfter(), 'the bot is rated too');

        // Past the leaderboard's minimum number of games, both would be listed: only the human is.
        $this->em()->getConnection()->executeStatement('UPDATE user_rating SET games_played = 100, last_rated_at = now()');
        $ratings = static::getContainer()->get(UserRatingRepository::class)->createLeaderboardQueryBuilder(SpeedCategory::BLITZ)->getQuery()->getResult();
        $listed = array_map(static fn (UserRating $rating): string => $rating->getUser()->getEmail(), $ratings);
        self::assertSame(['human@example.com'], $listed);
    }
}
