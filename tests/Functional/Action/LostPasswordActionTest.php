<?php

declare(strict_types=1);

namespace App\Tests\Functional\Action;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\TestDouble\RecordingLogger;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;

/**
 * PHP-SYMFONY-3 (Sentry): a Scaleway send failure must not turn
 * /login/lost-password into a 500, and - per 05-social.md sec 2.1 - the
 * response must be byte-identical whether or not the address has an
 * account, mail failure included.
 *
 * There is no live database in this execution context, so UserRepository
 * is replaced with an in-memory double for the test container; routing,
 * CSRF, the form, the controller, the real EntityManagerInterface,
 * UserMailer and the logger all run for real through the kernel.
 * EntityManagerInterface is left untouched: the double User below is never
 * persist()-ed, so it never enters the UnitOfWork, and
 * UnitOfWork::commit() (called by LostPasswordAction's flush()) returns
 * early as "nothing to do" without opening a database connection
 * (vendor/doctrine/orm/src/UnitOfWork.php, top of commit()). Run the suite
 * again inside `docker compose exec php bin/phpunit` against the real
 * stack before merging.
 *
 * CSRF (config/packages/csrf.yaml: stateless "submit" token) is satisfied
 * here via the same-origin check (Symfony\Component\Security\Csrf\
 * SameOriginCsrfTokenManager::isValidOrigin()): an Origin header matching
 * the request's own host, plus the unmodified "csrf-token" placeholder the
 * form renders server-side for the case where the double-submit JS hasn't
 * run - see formData().
 *
 * Assertions only look at 'error'-level records: with the app's LoggerInterface
 * swapped for RecordingLogger, framework-internal debug/info logging (routing,
 * firewall, CSRF acceptance...) is captured too, but that's an artifact of the
 * test double, not something either flow under test is expected to control.
 */
final class LostPasswordActionTest extends WebTestCase
{
    private const string ORIGIN = 'http://localhost';

    public function testExistingAccountGetsTheGenericResponseAndNothingIsLogged(): void
    {
        $user = new User('existing@example.com');
        $logger = new RecordingLogger();
        $client = $this->createClientWithDoubles(user: $user, logger: $logger, mailerThrows: false);

        $client->request('POST', '/login/lost-password', $this->formData('existing@example.com'), [], $this->serverParams());

        self::assertResponseRedirects('/login');
        self::assertNotNull($user->getResetToken(), 'a reset token must be stored for an existing account');
        self::assertSame([], $this->errorRecords($logger), 'a successful send must not log any error');
    }

    public function testUnknownAccountGetsTheSameGenericResponseAndNothingIsLogged(): void
    {
        $logger = new RecordingLogger();
        $client = $this->createClientWithDoubles(user: null, logger: $logger, mailerThrows: false);

        $client->request('POST', '/login/lost-password', $this->formData('nobody@example.com'), [], $this->serverParams());

        self::assertResponseRedirects('/login');
        self::assertSame([], $this->errorRecords($logger), 'looking up an unknown address must never log an error');
    }

    public function testMailerOutageOnAnExistingAccountStillGetsTheGenericResponse(): void
    {
        $user = new User('existing@example.com');
        $logger = new RecordingLogger();
        $client = $this->createClientWithDoubles(user: $user, logger: $logger, mailerThrows: true);

        $client->request('POST', '/login/lost-password', $this->formData('existing@example.com'), [], $this->serverParams());

        self::assertResponseRedirects('/login');
        self::assertNotNull($user->getResetToken(), 'the token is stored before the send is attempted, so it survives the outage');

        $errors = $this->errorRecords($logger);
        self::assertCount(2, $errors, 'UserMailer logs the transport failure, LostPasswordAction logs that the user was not notified');

        foreach ($errors as $record) {
            self::assertStringNotContainsString('existing@example.com', $record['message']);
            self::assertStringNotContainsString('existing@example.com', json_encode($record, \JSON_THROW_ON_ERROR));
        }
    }

    public function testMailerOutageOnAnUnknownAccountIsIndistinguishableFromSuccess(): void
    {
        // No user is found, so UserMailer is never even called: an outage
        // configured here must have zero observable effect, exactly like
        // the nominal "unknown account" case.
        $logger = new RecordingLogger();
        $client = $this->createClientWithDoubles(user: null, logger: $logger, mailerThrows: true);

        $client->request('POST', '/login/lost-password', $this->formData('nobody@example.com'), [], $this->serverParams());

        self::assertResponseRedirects('/login');
        self::assertSame([], $this->errorRecords($logger));
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    private function errorRecords(RecordingLogger $logger): array
    {
        return array_values(array_filter($logger->records, static fn (array $record): bool => 'error' === $record['level']));
    }

    /** @return array<string, mixed> */
    private function formData(string $email): array
    {
        return [
            'lost_password' => [
                // The literal cookie name is the form's own server-rendered placeholder for
                // "the double-submit JS hasn't run"; SameOriginCsrfTokenManager accepts it
                // and falls back to the Origin check set up in serverParams().
                'email' => $email,
                '_token' => 'csrf-token',
            ],
        ];
    }

    /** @return array<string, string> */
    private function serverParams(): array
    {
        return ['HTTP_ORIGIN' => self::ORIGIN];
    }

    private function createClientWithDoubles(?User $user, LoggerInterface $logger, bool $mailerThrows): KernelBrowser
    {
        $client = self::createClient();
        $container = self::getContainer();

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findByEmail')->willReturn($user);
        $container->set(UserRepository::class, $userRepository);

        if ($mailerThrows) {
            $mailer = $this->createMock(MailerInterface::class);
            $mailer->method('send')->willThrowException(new TransportException('Unable to send an email: invalid argument(s) (code 400).'));
            $container->set(MailerInterface::class, $mailer);
        }

        $container->set(LoggerInterface::class, $logger);

        return $client;
    }
}
