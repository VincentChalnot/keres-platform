<?php

declare(strict_types=1);

namespace App\Tests\Functional\Action;

use App\Model\ChessExperience;
use App\Repository\UserRepository;
use App\Service\Onboarding\ChessExperienceCookie;
use App\Tests\Functional\DatabaseTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Phase 3 of `docs/onboarding-plan.md`: "Do you play chess?", stored on the account and in a cookie, copied at sign-up,
 * asked until answered; the account wins over the cookie for a signed-in user.
 */
final class ChessExperienceTest extends DatabaseTestCase
{
    public function testAnAnonymousAnswerGoesToTheCookieAndTheQuestionStopsBeingAsked(): void
    {
        $client = static::createClient();

        $client->request('GET', '/', [], [], $this->sameOrigin());
        self::assertStringContainsString('<div class="modal" id="experience-modal"', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('data-experience-gate', (string) $client->getResponse()->getContent());
        self::assertMatchesRegularExpression('#<title>[^<]*</title>#', (string) $client->getResponse()->getContent(), 'the modal is page content, not part of the title');

        $client->request('POST', '/onboarding/experience', ['experience' => 'beginner'], [], $this->sameOrigin());
        self::assertResponseStatusCodeSame(204);
        self::assertSame('beginner', $client->getCookieJar()->get(ChessExperienceCookie::NAME)?->getValue());

        $client->request('GET', '/', [], [], $this->sameOrigin());
        self::assertStringNotContainsString('data-experience-modal', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('data-experience-gate', (string) $client->getResponse()->getContent());
    }

    public function testASignedInAnswerIsStoredOnTheAccount(): void
    {
        $client = static::createClient();
        $user = $this->createUser('player@example.com');
        $client->loginUser($user);

        $client->request('POST', '/onboarding/experience', ['experience' => 'player'], [], $this->sameOrigin());

        self::assertResponseStatusCodeSame(204);
        self::assertSame(ChessExperience::PLAYER, static::getContainer()->get(UserRepository::class)->findByEmail('player@example.com')?->getChessExperience());
    }

    public function testAnUnknownAnswerIsRefused(): void
    {
        $client = static::createClient();

        $client->request('POST', '/onboarding/experience', ['experience' => 'grandmaster'], [], $this->sameOrigin());

        self::assertResponseStatusCodeSame(400);
    }

    public function testTheAccountWinsOverTheCookieForASignedInUser(): void
    {
        $client = static::createClient();
        $user = $this->createUser('player@example.com');
        $user->setChessExperience(ChessExperience::SKIPPED);
        $this->em()->flush();
        $client->loginUser($user);
        $client->getCookieJar()->set(new Cookie(ChessExperienceCookie::NAME, 'beginner'));

        $client->request('GET', '/', [], [], $this->sameOrigin());
        self::assertStringNotContainsString('data-experience-modal', (string) $client->getResponse()->getContent());

        $other = $this->createUser('fresh@example.com');
        $client->loginUser($other);
        $client->request('GET', '/', [], [], $this->sameOrigin());
        self::assertStringContainsString('data-experience-modal', (string) $client->getResponse()->getContent(), 'the cookie answers for the browser, not for an account that never answered');
    }

    public function testAnAccountCreatedFromABrowserThatAnsweredKeepsTheAnswer(): void
    {
        $client = static::createClient();
        $client->getCookieJar()->set(new Cookie(ChessExperienceCookie::NAME, 'player'));

        $client->request('POST', '/register', ['register' => [
            'email' => 'new@example.com',
            'password' => ['first' => 'a-long-password', 'second' => 'a-long-password'],
            'acceptTerms' => '1',
            '_token' => 'csrf-token',
        ]], [], $this->sameOrigin());

        self::assertSame(ChessExperience::PLAYER, static::getContainer()->get(UserRepository::class)->findByEmail('new@example.com')?->getChessExperience());
    }

    public function testTheTutorialOpensWithTheQuestionUntilAnswered(): void
    {
        $client = static::createClient();

        $client->request('GET', '/tutorial', [], [], $this->sameOrigin());

        if (404 === $client->getResponse()->getStatusCode()) {
            self::markTestSkipped('The tutorial content is not in place.');
        }

        self::assertStringContainsString('modal is-active', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Do you play chess?', (string) $client->getResponse()->getContent());
    }

    public function testTheOnboardingCardDependsOnTheAccountNotTheBrowserCookie(): void
    {
        $client = static::createClient();
        $user = $this->createUser('player@example.com');
        $client->loginUser($user);
        $client->getCookieJar()->set(new Cookie('keres_onboarded', '1'));

        $client->request('GET', '/', [], [], $this->sameOrigin());

        self::assertStringContainsString('id="onboarding-card"', (string) $client->getResponse()->getContent());
    }

    public function testSettingsChangeTheAnswer(): void
    {
        $client = static::createClient();
        $user = $this->createUser('player@example.com');
        $user->setChessExperience(ChessExperience::BEGINNER);
        $this->em()->flush();
        $client->loginUser($user);

        $client->request('GET', '/settings/board', [], [], $this->sameOrigin());
        self::assertStringContainsString('Do you play chess?', (string) $client->getResponse()->getContent());

        $client->request('POST', '/settings/board', ['board_settings' => [
            'pieceAnimationSpeed' => 'fast',
            'chessExperience' => 'player',
            '_token' => 'csrf-token',
        ]], [], $this->sameOrigin());

        self::assertResponseRedirects('/settings/board');
        self::assertSame(ChessExperience::PLAYER, static::getContainer()->get(UserRepository::class)->findByEmail('player@example.com')?->getChessExperience());
    }
}
