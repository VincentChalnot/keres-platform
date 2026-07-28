<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
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
    ) {
    }

    public function sendResetPasswordMail(User $user, string $resetUrl): void
    {
        $email = (new TemplatedEmail())
            ->from('no-reply@keres.fr')
            ->to($user->getEmail())
            ->subject('Reset your Keres password')
            ->htmlTemplate('email/reset_password.html.twig')
            ->textTemplate('email/reset_password.txt.twig')
            ->context([
                'user' => $user,
                'resetUrl' => $resetUrl,
            ]);

        $this->mailer->send($email);
    }

    /** 05-social.md sec 2.1 / Open question 5: never disclose that the address is taken - notify the existing account instead. */
    public function sendAccountAlreadyExistsMail(User $user, string $lostPasswordUrl): void
    {
        $email = (new TemplatedEmail())
            ->from('no-reply@keres.fr')
            ->to($user->getEmail())
            ->subject('Someone tried to create an account with your email')
            ->htmlTemplate('email/account_exists.html.twig')
            ->textTemplate('email/account_exists.txt.twig')
            ->context([
                'user' => $user,
                'lostPasswordUrl' => $lostPasswordUrl,
            ]);

        $this->mailer->send($email);
    }
}
