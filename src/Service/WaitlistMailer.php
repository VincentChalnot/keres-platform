<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WaitlistSignup;
use App\Service\Locale\EmailLocale;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * T7: a waitlist signup has no `User` account behind it, so this can't
 * reuse `UserMailer` (typed to `User` throughout). Same
 * try/catch-and-redact shape as `UserMailer::send()` (PHP-SYMFONY-3) -
 * duplicated rather than shared, since there are only two occurrences so
 * far; worth extracting to a common base if a third mailer needs it.
 *
 * Locale: with no account there is no saved preference, so the mail is
 * written in the locale of the request that queues it
 * (`EmailLocale::forCurrentRequest()`, the visitor's language), pinned on the
 * message with `TemplatedEmail::locale()` so the async worker (default
 * locale) renders it the same way, and used for the subject translated here.
 */
class WaitlistMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $mailerFromAddress,
        private readonly EmailLocale $emailLocale,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function sendConfirmationMail(WaitlistSignup $signup, string $confirmUrl): void
    {
        $locale = $this->emailLocale->forCurrentRequest();
        $email = (new TemplatedEmail())
            ->from($this->mailerFromAddress)
            ->to($signup->getEmail())
            ->locale($locale)
            ->subject($this->translator->trans('waitlist_confirm.subject', [], 'emails', $locale))
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
