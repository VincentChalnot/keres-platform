<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Game;
use App\Entity\Notification;
use App\Entity\User;
use App\Model\GameEndReason;
use App\Model\Notification\NotificationPreferences;
use App\Model\Notification\NotificationType;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Model\TimeControlKind;
use App\Repository\NotificationRepository;
use App\Service\Game\GameUpdatePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The single write path for in-app notifications (07-notifications.md sec
 * 7.2). In-app only for now: no Web Push, no email. `readonly`, no mutable
 * state, safe under FrankenPHP worker mode.
 *
 * Two entry styles, because callers differ in where they sit relative to
 * their transaction:
 * - `notify()` for post-commit callers: persists, flushes, then pushes a
 *   `notification` frame on `user/{uuid}` so the bell updates live;
 * - `record()` for callers *inside* a transaction they flush themselves
 *   (`GameLifecycleManager`): persists only, no flush and no frame - a
 *   frame published before commit could be read before the row exists.
 *   The bell's periodic refresh picks those rows up.
 */
final readonly class NotificationCenter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationRepository $notificationRepository,
        private NotificationFormatter $formatter,
        private GameUpdatePublisher $publisher,
        private ClockInterface $clock,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function notify(User $recipient, NotificationType $type, array $payload, ?string $subject = null): void
    {
        $notification = $this->record($recipient, $type, $payload, $subject);

        if (null === $notification) {
            return;
        }

        $this->entityManager->flush();
        $this->publish($recipient, $notification);
    }

    /**
     * Persists without flushing. When `$subject` is given and an unread row
     * of the same type/subject exists, that row is refreshed instead of
     * stacking a duplicate.
     *
     * @param array<string, mixed> $payload
     */
    public function record(User $recipient, NotificationType $type, array $payload, ?string $subject = null): ?Notification
    {
        if (!NotificationPreferences::fromUser($recipient)->isInAppEnabled($type)) {
            return null;
        }

        $now = $this->clock->now();
        $existing = null !== $subject ? $this->notificationRepository->findUnreadBySubject($recipient, $type, $subject) : null;

        if (null !== $existing) {
            $existing->refresh($payload, $now);

            return $existing;
        }

        $notification = new Notification($recipient, $type, $payload, $subject, $now);
        $this->entityManager->persist($notification);

        return $notification;
    }

    /** `SeekMatcher`, post-commit: the owner of the seek someone else's seek/accept just consumed. */
    public function seekMatched(User $seekOwner, User $opponent, Game $game): void
    {
        $this->notify($seekOwner, NotificationType::SEEK_MATCHED, [
            'actor' => self::actorRef($opponent),
            'gameUuid' => $game->getUuid()->toRfc4122(),
        ], self::gameSubject($game));
    }

    /**
     * `SubmitMoveAction`, post-commit. Correspondence and unlimited games
     * only: in a clocked real-time game the player is watching the board,
     * and one row per ply would bury everything else in the inbox.
     */
    public function movePlayed(Game $game, User $mover): void
    {
        if (OpponentType::MULTIPLAYER !== $game->getOpponentType() || $game->isGameOver() || TimeControlKind::REALTIME === $game->getTimeControl()->getKind()) {
            return;
        }

        foreach ($game->getPlayers() as $player) {
            $user = $player->getUser();

            if (null === $user || $user === $mover) {
                continue;
            }

            $this->notify($user, NotificationType::YOUR_TURN, [
                'actor' => self::actorRef($mover),
                'gameUuid' => $game->getUuid()->toRfc4122(),
            ], self::gameSubject($game));
        }
    }

    /**
     * `GameLifecycleManager`, inside the finalising transaction (hence
     * `record()`). Multiplayer games only - an AI or hot-seat player saw
     * the end on their own screen. `$actor` (the resigner) is skipped.
     */
    public function gameFinished(Game $game, ?PieceColor $actor = null): void
    {
        if (OpponentType::MULTIPLAYER !== $game->getOpponentType()) {
            return;
        }

        foreach ($game->getPlayers() as $player) {
            $user = $player->getUser();

            if (null === $user || $player->getColor() === $actor) {
                continue;
            }

            $opponent = $game->getPlayer($player->getColor()->opposite())->getUser();
            $outcome = match (true) {
                GameEndReason::ABORTED === $game->getEndReason() => 'aborted',
                $game->isDraw() => 'draw',
                $game->isWhiteWins() === (PieceColor::WHITE === $player->getColor()) => 'win',
                default => 'loss',
            };

            // A pending "your turn" for a finished game is stale noise.
            $this->notificationRepository->markAllRead($user, $this->clock->now(), self::gameSubject($game));

            $this->record($user, NotificationType::GAME_FINISHED, [
                'actor' => null !== $opponent ? self::actorRef($opponent) : null,
                'gameUuid' => $game->getUuid()->toRfc4122(),
                'outcome' => $outcome,
                'endReason' => strtolower($game->getEndReason()->name),
            ], self::gameSubject($game));
        }
    }

    public function markGameRead(User $user, Game $game): void
    {
        $this->markSubjectRead($user, self::gameSubject($game));
    }

    public function markSubjectRead(User $user, string $subject): void
    {
        $this->notificationRepository->markAllRead($user, $this->clock->now(), $subject);
    }

    public function unreadCount(User $user): int
    {
        return $this->notificationRepository->countUnread($user);
    }

    /** @return array{username: string, displayName: ?string} */
    public static function actorRef(User $user): array
    {
        return ['username' => $user->getUsername(), 'displayName' => $user->getDisplayName()];
    }

    public static function gameSubject(Game $game): string
    {
        return 'game:'.$game->getUuid()->toRfc4122();
    }

    public static function userSubject(User $user): string
    {
        return 'user:'.$user->getId()->toRfc4122();
    }

    private function publish(User $recipient, Notification $notification): void
    {
        $now = $this->clock->now();

        // Same `UserEventPayload` envelope as the friend events
        // (02-realtime.md sec 4.2), so one `user/{uuid}` subscriber reads both.
        $this->publisher->publishUserEvent($recipient->getId()->toRfc4122(), $this->formatter->encode([
            'type' => 'user.event',
            'event' => 'notification',
            'notificationUuid' => $notification->getUuid()->toRfc4122(),
            'createdAt' => (int) $notification->getCreatedAt()->format('Uu'),
            'unreadCount' => $this->notificationRepository->countUnread($recipient),
            'data' => $this->formatter->format($notification),
            'serverTime' => (int) $now->format('Uu'),
        ]));
    }
}
