<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Adapted from SidusUserBundle\Mailer\UserMailer. Two messages: the
 * password-reset link, and the 05-social.md sec 2.1 registration-oracle
 * fix - notifying an existing account when someone tries to register
 * with its email instead of disclosing that the address is already taken.
 */
class UserMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $mailerFromAddress,
    ) {
    }

    public function sendResetPasswordMail(User $user, string $resetUrl): void
    {
        $email = (new TemplatedEmail())
            ->from($this->mailerFromAddress)
            ->to($user->getEmail())
            ->subject('Reset your Keres password')
            ->htmlTemplate('email/reset_password.html.twig')
            ->textTemplate('email/reset_password.txt.twig')
            ->context([
                'user' => $user,
                'resetUrl' => $resetUrl,
            ]);

        $this->send($email, 'reset password');
    }

    /** 05-social.md sec 2.1 / Open question 5: never disclose that the address is taken - notify the existing account instead. */
    public function sendAccountAlreadyExistsMail(User $user, string $lostPasswordUrl): void
    {
        $email = (new TemplatedEmail())
            ->from($this->mailerFromAddress)
            ->to($user->getEmail())
            ->subject('Someone tried to create an account with your email')
            ->htmlTemplate('email/account_exists.html.twig')
            ->textTemplate('email/account_exists.txt.twig')
            ->context([
                'user' => $user,
                'lostPasswordUrl' => $lostPasswordUrl,
            ]);

        $this->send($email, 'account already exists notice');
    }

    /**
     * Callers decide whether a transport failure is fatal for their flow;
     * this only logs the diagnostic detail Scaleway (or any HTTP transport)
     * gives us before rethrowing unchanged.
     */
    private function send(TemplatedEmail $email, string $mailKind): void
    {
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $context = ['exception' => $exception];

            if ($exception instanceof HttpTransportException && null !== $exception->getResponse()) {
                // getContent(false): read the body even on a non-2xx response, without throwing again.
                $context['response_body'] = self::redactEmailAddresses($exception->getResponse()->getContent(false));
            }

            $this->logger->error(\sprintf('Unable to send the "%s" email.', $mailKind), $context);

            throw $exception;
        }
    }

    /** Scaleway's error payloads can echo back the request, including recipient/sender addresses; never let those reach the logs. */
    private static function redactEmailAddresses(string $content): string
    {
        return preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/', '[redacted-email]', $content) ?? '[response body could not be redacted]';
    }
}
