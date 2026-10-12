<?php

declare(strict_types=1);

namespace App\Tests\Functional\Action;

use App\Repository\UserRepository;
use App\Service\Onboarding\OnboardingCookie;
use App\Tests\Functional\DatabaseTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * "Has been through the tutorial" is remembered in the browser (cookie) and follows the visitor into the account they
 * create afterwards or already have.
 */
final class OnboardingTest extends DatabaseTestCase
{
    public function testCompletingAnonymouslySetsTheBrowserCookie(): void
    {
        $client = static::createClient();

        $client->request('POST', '/onboarding/complete', [], [], $this->sameOrigin());

        self::assertResponseStatusCodeSame(204);
        $cookie = $client->getCookieJar()->get(OnboardingCookie::NAME);
        self::assertNotNull($cookie);
        self::assertSame('1', $cookie->getValue());
    }

    public function testCompletingWhileSignedInMarksTheAccount(): void
    {
        $client = static::createClient();
        $user = $this->createUser('learner@example.com');
        $client->loginUser($user);

        $client->request('POST', '/onboarding/complete', [], [], $this->sameOrigin());

        self::assertResponseStatusCodeSame(204);
        self::assertNotNull($user->getOnboardedAt());
    }

    public function testAnAccountCreatedByABrowserThatDidTheTutorialStartsOnboarded(): void
    {
        $client = static::createClient();
        $client->getCookieJar()->set(new Cookie(OnboardingCookie::NAME, '1'));

        $client->request('POST', '/register', ['register' => [
            'email' => 'new@example.com',
            'password' => ['first' => 'a-long-password', 'second' => 'a-long-password'],
            'acceptTerms' => '1',
            '_token' => 'csrf-token',
        ]], [], $this->sameOrigin());

        self::assertResponseRedirects('/login');
        $user = static::getContainer()->get(UserRepository::class)->findByEmail('new@example.com');
        self::assertNotNull($user);
        self::assertNotNull($user->getOnboardedAt());
    }

    public function testAnAccountCreatedWithoutTheCookieIsNotOnboarded(): void
    {
        $client = static::createClient();

        $client->request('POST', '/register', ['register' => [
            'email' => 'new@example.com',
            'password' => ['first' => 'a-long-password', 'second' => 'a-long-password'],
            'acceptTerms' => '1',
            '_token' => 'csrf-token',
        ]], [], $this->sameOrigin());

        $user = static::getContainer()->get(UserRepository::class)->findByEmail('new@example.com');
        self::assertNotNull($user);
        self::assertNull($user->getOnboardedAt());
    }
}
