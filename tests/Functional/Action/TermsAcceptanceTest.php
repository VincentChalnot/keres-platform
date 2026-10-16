<?php

declare(strict_types=1);

namespace App\Tests\Functional\Action;

use App\Entity\User;
use App\Model\TermsOfUse;
use App\Repository\UserRepository;
use App\Tests\Functional\DatabaseTestCase;

/**
 * The terms of use must be accepted to register, and by any signed-in account that has not accepted the current
 * version (OIDC sign-ups, accounts that predate the terms) before it can use a page.
 *
 * CSRF (stateless "submit" token) is satisfied through the same-origin check: an Origin header on the request's own
 * host plus the unmodified `csrf-token` placeholder the form renders (see LostPasswordActionTest).
 */
final class TermsAcceptanceTest extends DatabaseTestCase
{
    public function testRegistrationWithoutTheCheckboxCreatesNoAccount(): void
    {
        $client = static::createClient();

        $client->request('POST', '/register', ['register' => $this->registration(accept: false)], [], $this->sameOrigin());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('You must accept the terms of use', (string) $client->getResponse()->getContent());
        self::assertNull(static::getContainer()->get(UserRepository::class)->findByEmail('new@example.com'));
    }

    public function testRegistrationRecordsTheAcceptedVersion(): void
    {
        $client = static::createClient();

        $client->request('POST', '/register', ['register' => $this->registration(accept: true)], [], $this->sameOrigin());

        self::assertResponseRedirects('/login');
        $user = static::getContainer()->get(UserRepository::class)->findByEmail('new@example.com');
        self::assertNotNull($user);
        self::assertTrue($user->hasAcceptedTerms(TermsOfUse::CURRENT_VERSION));
    }

    public function testAccountWithoutAcceptanceIsSentToTheTermsPageAndBackAfterAccepting(): void
    {
        $client = static::createClient();
        $user = $this->createUser('oidc@example.com');
        $client->loginUser($user);

        $client->request('GET', '/lobby', [], [], $this->html());
        self::assertResponseRedirects('/terms/accept?redirect=/lobby');

        $client->request('POST', '/terms/accept?redirect=%2Flobby', ['accept_terms' => ['acceptTerms' => '1', '_token' => 'csrf-token']], [], $this->sameOrigin());
        self::assertResponseRedirects('/lobby');

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(User::class)->find($user->getId());
        self::assertTrue($reloaded->hasAcceptedTerms(TermsOfUse::CURRENT_VERSION));
    }

    public function testEveryRedirectTargetStaysOnThisSite(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('oidc@example.com'));

        $client->request('POST', '/terms/accept?redirect=//evil.example', ['accept_terms' => ['acceptTerms' => '1', '_token' => 'csrf-token']], [], $this->sameOrigin());

        self::assertResponseRedirects('/');
    }

    public function testBackgroundRequestsOfAnUnacceptedAccountAreNotRedirected(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('oidc@example.com'));

        $client->request('GET', '/notifications/unread-count', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'] + $this->sameOrigin());

        self::assertResponseIsSuccessful();
    }

    /** @return array<string, string> */
    private function html(): array
    {
        return $this->sameOrigin() + ['HTTP_ACCEPT' => 'text/html'];
    }

    /** @return array<string, mixed> */
    private function registration(bool $accept): array
    {
        $data = [
            'email' => 'new@example.com',
            'password' => ['first' => 'a-long-password', 'second' => 'a-long-password'],
            '_token' => 'csrf-token',
        ];

        if ($accept) {
            $data['acceptTerms'] = '1';
        }

        return $data;
    }
}
