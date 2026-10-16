<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service\Ladder;

use App\Entity\Game;
use App\Entity\User;
use App\Form\LocalGameType;
use App\Model\ColorPreference;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Model\TimeControl;
use App\Repository\UserRepository;
use App\Service\BotTournament\BotAccounts;
use App\Service\Game\GameLifecycleManager;
use App\Service\GameFactory;
use App\Tests\Functional\DatabaseTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * The AI ladder (`User::$highestAiLevelBeaten`): a win against the engine counts - in a casual AI game as in a rated
 * game against a bot - unless an undo was used; nothing ever lowers it; a guest's cookie is copied to the new account.
 */
final class AiLadderTest extends DatabaseTestCase
{
    public function testWinningAgainstTheAiRecordsTheLevelAndAnUndoGameDoesNotCount(): void
    {
        static::bootKernel();
        $player = $this->createUser('player@example.com');

        $this->finishAiGameWonByThePlayer($player, level: 3);
        self::assertSame(3, $player->getHighestAiLevelBeaten());

        $this->finishAiGameWonByThePlayer($player, level: 5, undoUsed: true);
        self::assertSame(3, $player->getHighestAiLevelBeaten(), 'a game with an undo does not count');

        $this->finishAiGameWonByThePlayer($player, level: 2);
        self::assertSame(3, $player->getHighestAiLevelBeaten(), 'an easier bot never lowers the ladder');
    }

    public function testLosingToTheAiCountsForNothing(): void
    {
        static::bootKernel();
        $player = $this->createUser('player@example.com');
        $game = $this->startAiGame($player, 4);

        $this->finish(static fn (GameLifecycleManager $manager) => $manager->resign($game, PieceColor::WHITE));

        self::assertNull($player->getHighestAiLevelBeaten());
    }

    public function testBeatingARatedBotCountsButLosingToItDoesNot(): void
    {
        static::bootKernel();
        $bot = $this->em()->find(User::class, static::getContainer()->get(BotAccounts::class)->ensure([5])[5]);
        $player = $this->createUser('player@example.com');
        $factory = static::getContainer()->get(GameFactory::class);

        $lost = $factory->createBotGame($player, $bot, 5, ColorPreference::WHITE, TimeControl::realtime(420, 5));
        $this->em()->persist($lost);
        $this->em()->flush();
        $this->addPlies($lost, 4);
        $this->finish(static fn (GameLifecycleManager $manager) => $manager->resign($lost, PieceColor::WHITE));
        self::assertNull($player->getHighestAiLevelBeaten());

        $won = $factory->createBotGame($player, $bot, 5, ColorPreference::WHITE, TimeControl::realtime(420, 5));
        $this->em()->persist($won);
        $this->em()->flush();
        $this->addPlies($won, 4);
        $this->finish(static fn (GameLifecycleManager $manager) => $manager->resign($won, PieceColor::BLACK));
        self::assertSame(5, $player->getHighestAiLevelBeaten());
    }

    public function testAnAccountCreatedWithAGuestCookieStartsOnTheLadderCappedAtTheGuestLimit(): void
    {
        $client = static::createClient();
        $client->getCookieJar()->set(new Cookie('keres_ai_beaten', '7'));

        $client->request('POST', '/register', ['register' => [
            'email' => 'new@example.com',
            'password' => ['first' => 'a-long-password', 'second' => 'a-long-password'],
            'acceptTerms' => '1',
            '_token' => 'csrf-token',
        ]], [], $this->sameOrigin());

        $user = static::getContainer()->get(UserRepository::class)->findByEmail('new@example.com');
        self::assertNotNull($user);
        self::assertSame(LocalGameType::GUEST_MAX_AI_LEVEL, $user->getHighestAiLevelBeaten());
    }

    private function startAiGame(User $player, int $level, bool $undoUsed = false): Game
    {
        $game = static::getContainer()->get(GameFactory::class)->createAiOrHotseatGame($player, OpponentType::AI, ColorPreference::WHITE, $level);

        if ($undoUsed) {
            $game->markUndoUsed();
        }
        $this->em()->persist($game);
        $this->em()->flush();

        return $game;
    }

    private function finishAiGameWonByThePlayer(User $player, int $level, bool $undoUsed = false): void
    {
        $game = $this->startAiGame($player, $level, $undoUsed);

        // The engine side (black) resigns: the player, white, won
        $this->finish(static fn (GameLifecycleManager $manager) => $manager->resign($game, PieceColor::BLACK));
    }

    private function finish(callable $finishing): void
    {
        $this->em()->wrapInTransaction(static fn () => $finishing(static::getContainer()->get(GameLifecycleManager::class)));
    }
}
