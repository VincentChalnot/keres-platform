<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WaitlistSignup;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * T7: a waitlist signup has no `User` account behind it, so this can't
 * reuse `UserMailer` (typed to `User` throughout). Same
 * try/catch-and-redact shape as `UserMailer::send()` (PHP-SYMFONY-3) -
 * duplicated rather than shared, since there are only two occurrences so
 * far; worth extracting to a common base if a third mailer needs it.
 */
class WaitlistMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $mailerFromAddress,
    ) {
    }

    public function sendConfirmationMail(WaitlistSignup $signup, string $confirmUrl): void
    {
        $email = (new TemplatedEmail())
            ->from($this->mailerFromAddress)
            ->to($signup->getEmail())
            ->subject('Confirm your spot on the Keres physical edition waitlist')
            ->htmlTemplate('email/waitlist_confirm.html.twig')
            ->textTemplate('email/waitlist_confirm.txt.twig')
            ->context([
                'signup' => $signup,
                'confirmUrl' => $confirmUrl,
            ]);

        $this->send($email, 'waitlist confirmation');
    }

    private function send(TemplatedEmail $email, string $mailKind): void
    {
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $context = ['exception' => $exception];

            if ($exception instanceof HttpTransportException && null !== $exception->getResponse()) {
                $context['response_body'] = self::redactEmailAddresses($exception->getResponse()->getContent(false));
            }

            $this->logger->error(\sprintf('Unable to send the "%s" email.', $mailKind), $context);

            throw $exception;
        }
    }

    private static function redactEmailAddresses(string $content): string
    {
        return preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/', '[redacted-email]', $content) ?? '[response body could not be redacted]';
    }
}
