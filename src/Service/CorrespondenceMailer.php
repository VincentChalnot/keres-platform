<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Game;
use App\Entity\User;
use App\Service\Locale\EmailLocale;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * T8: the correspondence deadline sweep's warning email. `UserMailer` is
 * a reasonable place this could have lived (recipient is always a real
 * `User`), but this follows the `WaitlistMailer`/`AdminNotificationMailer`
 * precedent of one dedicated mailer per feature instead. Same
 * try/catch-and-redact shape as `UserMailer::send()` (PHP-SYMFONY-3). Runs
 * from a console command (no HTTP request), so URL generation relies on
 * `framework.router.default_uri` (config/packages/routing.yaml), same as
 * `NotificationFormatter`.
 *
 * Locale: written in the recipient's saved locale (`EmailLocale::forUser()`,
 * default locale if none/unsupported) - there is no request here - pinned on
 * the message with `TemplatedEmail::locale()` and used for the subject
 * translated here.
 */
class CorrespondenceMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $mailerFromAddress,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EmailLocale $emailLocale,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function sendDeadlineWarning(User $user, Game $game): void
    {
        $playUrl = $this->urlGenerator->generate(
            'play',
            ['uuid' => $game->getUuid()->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $locale = $this->emailLocale->forUser($user);
        $email = (new TemplatedEmail())
            ->from($this->mailerFromAddress)
            ->to($user->getEmail())
            ->locale($locale)
            ->subject($this->translator->trans('correspondence_deadline_warning.subject', [], 'emails', $locale))
            ->htmlTemplate('email/correspondence_deadline_warning.html.twig')
            ->textTemplate('email/correspondence_deadline_warning.txt.twig')
            ->context([
                'user' => $user,
                'game' => $game,
                'playUrl' => $playUrl,
            ]);

        $this->send($email, 'correspondence deadline warning');
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
