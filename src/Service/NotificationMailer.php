<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Game;
use App\Entity\User;
use App\Model\GameEndReason;
use App\Model\PieceColor;
use App\Service\Locale\EmailLocale;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * T9: email for the three notification types enabled for email by default
 * (`NotificationType::isEmailEnabledByDefault()`) - `NotificationCenter`
 * calls in after its own in-app `notify()`/`record()`, gated on
 * `NotificationPreferences::isEmailEnabled()`. Same try/catch-and-redact
 * shape as `UserMailer::send()` (PHP-SYMFONY-3). Every mail carries
 * `unsubscribe_url` -> `/settings/notifications` (T2's layout hook) - none
 * of these three are transactional/security mail.
 *
 * Locale: each mail is written in the recipient's saved locale
 * (`EmailLocale::forUser()`, default locale if none/unsupported), pinned on
 * the message with `TemplatedEmail::locale()` (the body renderer switches the
 * translator to it) and used for the subject translated here - never the
 * locale of the request that triggered the notification (the opponent's).
 */
class NotificationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $mailerFromAddress,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly EmailLocale $emailLocale,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Rate-limited to one per game per hour
     * (`Game::lastYourTurnEmailAt`/`markYourTurnEmailSent()`): a
     * correspondence player who plays several moves in quick succession
     * (e.g. catching up on a backlog) must not flood their opponent. The
     * check-then-write happens under a `PESSIMISTIC_WRITE` row lock in its
     * own transaction (this method is always called post-commit, from
     * `NotificationCenter::movePlayed()`), same shape as
     * `ClockAdjudicator::adjudicate()`/the T8 sweep's warning check.
     */
    public function sendYourTurn(User $recipient, Game $game, User $mover): void
    {
        $shouldSend = $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($game): bool {
            $em->getConnection()->executeStatement("SET LOCAL lock_timeout = '3s'");

            // SELECT ... FOR UPDATE + re-hydrate (same mutex ClockAdjudicator uses).
            $em->find(Game::class, $game->getId(), LockMode::PESSIMISTIC_WRITE);

            $now = $this->clock->now();
            $last = $game->getLastYourTurnEmailAt();

            if (null !== $last && $last > $now->modify('-1 hour')) {
                return false;
            }

            $game->markYourTurnEmailSent($now);
            $em->flush();

            return true;
        });

        if (!$shouldSend) {
            return;
        }

        $locale = $this->emailLocale->forUser($recipient);
        $email = (new TemplatedEmail())
            ->from($this->mailerFromAddress)
            ->to($recipient->getEmail())
            ->locale($locale)
            ->subject($this->translator->trans('notification_your_turn.subject', [], 'emails', $locale))
            ->htmlTemplate('email/notification_your_turn.html.twig')
            ->textTemplate('email/notification_your_turn.txt.twig')
            ->context([
                'recipient' => $recipient,
                'mover' => $mover,
                'playUrl' => $this->playUrl($game),
                'unsubscribe_url' => $this->unsubscribeUrl(),
            ]);

        $this->send($email, 'your turn');
    }

    /** No rate limit - fires at most once per game (invariant 5: a finished game is never reopened). */
    public function sendGameFinished(User $recipient, Game $game): void
    {
        $colors = $game->getColorsForUser($recipient);
        $outcome = self::outcomeFor($game, $colors[0] ?? null);

        $locale = $this->emailLocale->forUser($recipient);
        $email = (new TemplatedEmail())
            ->from($this->mailerFromAddress)
            ->to($recipient->getEmail())
            ->locale($locale)
            ->subject($this->translator->trans('notification_game_finished.subject', [], 'emails', $locale))
            ->htmlTemplate('email/notification_game_finished.html.twig')
            ->textTemplate('email/notification_game_finished.txt.twig')
            ->context([
                'recipient' => $recipient,
                'game' => $game,
                'outcome' => $outcome,
                'playUrl' => $this->playUrl($game),
                'unsubscribe_url' => $this->unsubscribeUrl(),
            ]);

        $this->send($email, 'game finished');
    }

    /** No rate limit - fires at most once per seek (a seek is consumed exactly once, invariant 12). */
    public function sendSeekMatched(User $recipient, Game $game, User $opponent): void
    {
        $locale = $this->emailLocale->forUser($recipient);
        $email = (new TemplatedEmail())
            ->from($this->mailerFromAddress)
            ->to($recipient->getEmail())
            ->locale($locale)
            ->subject($this->translator->trans('notification_seek_matched.subject', [], 'emails', $locale))
            ->htmlTemplate('email/notification_seek_matched.html.twig')
            ->textTemplate('email/notification_seek_matched.txt.twig')
            ->context([
                'recipient' => $recipient,
                'opponent' => $opponent,
                'playUrl' => $this->playUrl($game),
                'unsubscribe_url' => $this->unsubscribeUrl(),
            ]);

        $this->send($email, 'seek matched');
    }

    /**
     * Shared with `NotificationCenter::gameFinished()`'s in-app payload so
     * the two channels can never disagree about the same game's outcome.
     */
    public static function outcomeFor(Game $game, ?PieceColor $color): ?string
    {
        if (null === $color) {
            return null;
        }

        return match (true) {
            GameEndReason::ABORTED === $game->getEndReason() => 'aborted',
            $game->isDraw() => 'draw',
            $game->isWhiteWins() === (PieceColor::WHITE === $color) => 'win',
            default => 'loss',
        };
    }

    private function playUrl(Game $game): string
    {
        return $this->urlGenerator->generate('play', ['uuid' => $game->getUuid()->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function unsubscribeUrl(): string
    {
        return $this->urlGenerator->generate('settings_notifications', [], UrlGeneratorInterface::ABSOLUTE_URL);
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
