<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Feedback;
use App\Model\FeedbackCategory;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Operational alerts to the site owner - not user-facing notifications, so
 * this is deliberately separate from `UserMailer` (which only ever emails
 * end users) and never renders an unsubscribe link (see
 * templates/email/_layout.html.twig: simply never pass unsubscribe_url).
 *
 * `$adminNotificationEmail` (ADMIN_NOTIFICATION_EMAIL) is blank by default
 * (see .env.example) - every method here is a deliberate no-op when it's
 * unset, so a fresh install without an admin address configured never
 * throws trying to send to an empty recipient.
 */
final readonly class AdminNotificationMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private string $mailerFromAddress,
        private string $adminNotificationEmail,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function sendGdprRequestNotification(Feedback $feedback): void
    {
        if ('' === $this->adminNotificationEmail) {
            $this->logger->info('Skipping admin GDPR request notification: ADMIN_NOTIFICATION_EMAIL is not configured.');

            return;
        }

        $requestTypeLabel = match ($feedback->getCategory()) {
            FeedbackCategory::DATA_EXPORT_REQUEST => 'data export',
            FeedbackCategory::ACCOUNT_DELETION_REQUEST => 'account deletion',
            default => 'GDPR',
        };

        $reviewUrl = $this->urlGenerator->generate(
            'sidus_admin.Feedback.edit',
            ['id' => $feedback->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new TemplatedEmail())
            ->from($this->mailerFromAddress)
            ->to($this->adminNotificationEmail)
            ->subject(\sprintf('New %s request', $requestTypeLabel))
            ->htmlTemplate('email/admin_gdpr_request.html.twig')
            ->textTemplate('email/admin_gdpr_request.txt.twig')
            ->context([
                'feedback' => $feedback,
                'requestTypeLabel' => $requestTypeLabel,
                'reviewUrl' => $reviewUrl,
            ]);

        $this->mailer->send($email);
    }
}
