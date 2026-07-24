<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Adapted from SidusUserBundle\Mailer\UserMailer: only the reset-password
 * message is needed here (accounts are already created via OIDC/dev-login/
 * self-registration, so there's no "new account" welcome email to send).
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
}
