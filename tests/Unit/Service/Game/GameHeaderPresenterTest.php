<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Game;

use App\Entity\Game;
use App\Entity\GamePlayer;
use App\Entity\User;
use App\Model\GameHeaderBadge;
use App\Model\GameHeaderRating;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Model\TimeControl;
use App\Repository\UserRatingRepository;
use App\Service\Game\GameHeaderPresenter;
use App\Service\Rating\Glicko2Calculator;
use App\Service\Rating\RatingUpdater;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class GameHeaderPresenterTest extends TestCase
{
    public function testAiSeatIsNamedWithItsLevel(): void
    {
        $human = $this->user('human@example.com', 'alice');
        $game = new Game($human, OpponentType::AI, TimeControl::unlimited(), false, 7);
        new GamePlayer($game, PieceColor::WHITE, $human);
        new GamePlayer($game, PieceColor::BLACK, null);

        $header = $this->presenter()->present($game, $human);

        self::assertSame('alice', $header->white->label);
        self::assertSame('human', $header->white->kind);
        self::assertSame('AI (level 7)', $header->black->label);
        self::assertSame('ai', $header->black->kind);
    }

    public function testTournamentBotIsRecognisedEvenForAnonymousViewers(): void
    {
        $bot = $this->user('bot-level-4@playkeres.com', 'keres-bot-4');
        $bot->setDisplayName('Keres Bot (level 4)');
        $human = $this->user('human@example.com', 'alice');
        $game = $this->multiplayer($human, $bot);

        $header = $this->presenter()->present($game, null);

        self::assertSame('Keres Bot (level 4)', $header->black->label);
        self::assertSame('bot', $header->black->kind);
        self::assertSame('Player', $header->white->label);
        self::assertSame('anonymous', $header->white->kind);
        self::assertNull($header->white->username);
    }

    public function testProfileSubjectIsNamedEvenToAnAnonymousViewer(): void
    {
        $white = $this->user('white@example.com', 'alice');
        $black = $this->user('black@example.com', 'bob');

        $header = $this->presenter()->present($this->multiplayer($white, $black), null, $white);

        self::assertSame('alice', $header->white->label);
        self::assertSame('Player', $header->black->label);
        self::assertNull($header->black->username);
    }

    public function testSignedInViewerSeesUsernames(): void
    {
        $white = $this->user('white@example.com', 'alice');
        $black = $this->user('black@example.com', 'bob');

        $header = $this->presenter()->present($this->multiplayer($white, $black), $white);

        self::assertSame('alice', $header->white->username);
        self::assertSame('bob', $header->black->label);
    }

    public function testBadgesListOnlyTheOptionsTheGameHas(): void
    {
        $white = $this->user('white@example.com', 'alice');
        $black = $this->user('black@example.com', 'bob');
        $game = new Game($white, OpponentType::MULTIPLAYER, TimeControl::realtime(300, 3), true);
        new GamePlayer($game, PieceColor::WHITE, $white);
        new GamePlayer($game, PieceColor::BLACK, $black);

        $labels = array_map(static fn (GameHeaderBadge $badge): string => $badge->label, $this->presenter()->present($game, $white)->badges);

        self::assertSame(['Rated', '5+3', 'Blitz'], $labels);

        $casual = new Game($white, OpponentType::AI, TimeControl::unlimited(), false, 2, true);
        new GamePlayer($casual, PieceColor::WHITE, $white);
        new GamePlayer($casual, PieceColor::BLACK, null);

        $labels = array_map(static fn (GameHeaderBadge $badge): string => $badge->label, $this->presenter()->present($casual, $white)->badges);

        self::assertSame(['Casual', 'Unlimited', 'Live evaluation'], $labels);
    }

    public function testAvatarIsFilledForHumansAndBotsButNeverForAnonymousSeats(): void
    {
        $bot = $this->user('bot-level-4@playkeres.com', 'keres-bot-4');
        $bot->setAvatarUrl('https://img.test/bot.png');
        $human = $this->user('human@example.com', 'alice');
        $human->setAvatarUrl('https://img.test/alice.png');
        $game = $this->multiplayer($human, $bot);

        $signedIn = $this->presenter()->present($game, $human);
        $anonymous = $this->presenter()->present($game, null);

        self::assertSame('https://img.test/alice.png', $signedIn->white->avatarUrl);
        self::assertSame('https://img.test/bot.png', $signedIn->black->avatarUrl);
        self::assertNull($anonymous->white->avatarUrl);
        self::assertSame('https://img.test/bot.png', $anonymous->black->avatarUrl);
        self::assertNull($signedIn->white->rating);
    }

    public function testBoardHeaderUsesTheRecordedRatingAndDeltaOfAFinishedRatedGame(): void
    {
        $white = $this->user('white@example.com', 'alice');
        $black = $this->user('black@example.com', 'bob');
        $game = $this->ratedBlitz($white, $black);
        $game->getPlayer(PieceColor::WHITE)->writeRatingSnapshot(1500, 200, 1516, true);
        $game->getPlayer(PieceColor::BLACK)->writeRatingSnapshot(1480, 80, 1464, false);

        $header = $this->presenter()->presentForBoard($game, $white);

        self::assertEquals(new GameHeaderRating(1516, true, 16), $header->white->rating);
        self::assertEquals(new GameHeaderRating(1464, false, -16), $header->black->rating);
    }

    public function testBoardHeaderFallsBackToTheCurrentRatingWithoutDelta(): void
    {
        $white = $this->user('white@example.com', 'alice');
        $black = $this->user('black@example.com', 'bob');

        $header = $this->presenter()->presentForBoard($this->ratedBlitz($white, $black), $white);

        self::assertSame(1500, $header->white->rating?->value);
        self::assertTrue($header->white->rating->provisional);
        self::assertNull($header->white->rating->delta);
    }

    public function testBoardHeaderHasNoRatingForAnonymousSeats(): void
    {
        $white = $this->user('white@example.com', 'alice');
        $black = $this->user('black@example.com', 'bob');

        $header = $this->presenter()->presentForBoard($this->ratedBlitz($white, $black), null);

        self::assertNull($header->white->rating);
        self::assertNull($header->black->rating);
    }

    public function testBoardHeaderHasNoRatingForUnlimitedHotseatOrEngineGames(): void
    {
        $human = $this->user('human@example.com', 'alice');
        $other = $this->user('other@example.com', 'bob');

        $unlimited = $this->presenter()->presentForBoard($this->multiplayer($human, $other), $human);
        self::assertNull($unlimited->white->rating);

        $hotseat = new Game($human, OpponentType::HOTSEAT, TimeControl::realtime(300, 3), false);
        new GamePlayer($hotseat, PieceColor::WHITE, $human);
        new GamePlayer($hotseat, PieceColor::BLACK, $human);
        self::assertNull($this->presenter()->presentForBoard($hotseat, $human)->white->rating);

        $ai = new Game($human, OpponentType::AI, TimeControl::realtime(300, 3), false, 3);
        new GamePlayer($ai, PieceColor::WHITE, $human);
        new GamePlayer($ai, PieceColor::BLACK, null);
        $header = $this->presenter()->presentForBoard($ai, $human);
        self::assertNull($header->black->rating);
    }

    private function presenter(): GameHeaderPresenter
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', \dirname(__DIR__, 4).'/translations/game+intl-icu.en.yaml', 'en', 'game+intl-icu');

        $ratings = $this->createStub(UserRatingRepository::class);
        $ratings->method('findOneFor')->willReturn(null);
        $updater = new RatingUpdater($this->createStub(EntityManagerInterface::class), $ratings, new Glicko2Calculator());

        return new GameHeaderPresenter($translator, $updater, new MockClock('2026-10-10 12:00:00'));
    }

    private function ratedBlitz(User $white, User $black): Game
    {
        $game = new Game($white, OpponentType::MULTIPLAYER, TimeControl::realtime(300, 3), true);
        new GamePlayer($game, PieceColor::WHITE, $white);
        new GamePlayer($game, PieceColor::BLACK, $black);

        return $game;
    }

    private function multiplayer(User $white, User $black): Game
    {
        $game = new Game($white, OpponentType::MULTIPLAYER, TimeControl::unlimited(), false);
        new GamePlayer($game, PieceColor::WHITE, $white);
        new GamePlayer($game, PieceColor::BLACK, $black);

        return $game;
    }

    private function user(string $email, string $username): User
    {
        $user = new User($email);
        $user->setUsername($username);

        return $user;
    }
}
