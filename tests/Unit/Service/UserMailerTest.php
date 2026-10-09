<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Service\Locale\EmailLocale;
use App\Service\Locale\LocaleResolver;
use App\Service\UserMailer;
use App\Tests\TestDouble\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Covers the observability added around PHP-SYMFONY-3 (Sentry): a Scaleway
 * HttpTransportException must be logged with its response body, without
 * ever leaking the recipient's email address, and must still propagate so
 * callers can decide what to do (see LostPasswordActionTest for the
 * caller side of that contract).
 */
final class UserMailerTest extends TestCase
{
    private const string FROM_ADDRESS = 'noreply@app.example.test';

    public function testSuccessfulSendDoesNotLogAnything(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $logger = new RecordingLogger();
        $userMailer = $this->createUserMailer($mailer, $logger);

        $userMailer->sendResetPasswordMail(new User('victim@example.com'), 'https://app.example.test/reset?token=abc');

        self::assertSame([], $logger->records);
    }

    public function testHttpTransportFailureIsLoggedWithRedactedBodyAndRethrown(): void
    {
        $response = (new MockHttpClient(new MockResponse(
            json_encode([
                'message' => 'invalid argument(s)',
                'to' => [['email' => 'victim@example.com']],
                'from' => ['email' => self::FROM_ADDRESS],
            ], \JSON_THROW_ON_ERROR),
            ['http_code' => 400]
        )))->request('POST', 'https://api.scaleway.example/emails');
        // Force the mock response to complete so getStatusCode()/getContent() are readable below.
        $response->getStatusCode();

        $exception = new HttpTransportException('Unable to send an email: invalid argument(s) (code 400).', $response);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException($exception);

        $logger = new RecordingLogger();
        $userMailer = $this->createUserMailer($mailer, $logger);

        $caught = null;

        try {
            $userMailer->sendResetPasswordMail(new User('victim@example.com'), 'https://app.example.test/reset?token=abc');
        } catch (TransportExceptionInterface $caught) {
        }

        self::assertNotNull($caught, 'the transport exception must propagate to the caller');
        self::assertSame($exception, $caught);

        self::assertCount(1, $logger->records);
        $record = $logger->records[0];
        self::assertSame('error', $record['level']);
        self::assertStringContainsString('invalid argument(s)', $record['context']['response_body']);
        self::assertStringNotContainsString('victim@example.com', $record['context']['response_body']);
        self::assertStringNotContainsString(self::FROM_ADDRESS, $record['context']['response_body']);

        // Belt and braces: no email address anywhere in the whole log record, not just the body field.
        self::assertStringNotContainsString('victim@example.com', json_encode($record, \JSON_THROW_ON_ERROR));
    }

    public function testNonHttpTransportFailureIsLoggedWithoutAResponseBody(): void
    {
        $exception = new TransportException('Could not reach the remote Scaleway server.');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException($exception);

        $logger = new RecordingLogger();
        $userMailer = $this->createUserMailer($mailer, $logger);

        $caught = null;

        try {
            $userMailer->sendResetPasswordMail(new User('victim@example.com'), 'https://app.example.test/reset?token=abc');
        } catch (TransportExceptionInterface $caught) {
        }

        self::assertNotNull($caught);
        self::assertCount(1, $logger->records);
        self::assertArrayNotHasKey('response_body', $logger->records[0]['context']);
    }

    private function createUserMailer(MailerInterface $mailer, RecordingLogger $logger): UserMailer
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('en');

        return new UserMailer(
            $mailer,
            $logger,
            self::FROM_ADDRESS,
            new EmailLocale(new LocaleResolver(['en', 'fr'], 'en'), $translator, 'en'),
            $translator,
        );
    }
}
