<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Friendship;
use App\Entity\User;
use App\Model\SpeedCategory;
use App\Service\Rating\RatingUpdater;

/**
 * `UserEventPayload` for the two events this phase publishes to
 * `user/{uuid}` (02-realtime.md sec 4.2, sec 7 row 17):
 * `friend_request`/`friend_accepted`. Grown incrementally as later phases
 * add more `NotificationType` variants - this is not a general-purpose
 * notification builder yet.
 *
 * `notificationUuid` is always `null` and `unreadCount` always `0`: the
 * `Notification` entity and its durable inbox/unread-count bookkeeping
 * land in Phase 6 (`07-notifications.md`). Both are real, spec-compliant
 * values for an event that "mints no durable row" (02-realtime.md sec
 * 4.2) - not a placeholder that lies, because nothing is unread yet
 * either. Phase 6 starts persisting a `Notification` row per event and
 * this builder's `notificationUuid`/`unreadCount` become real.
 */
final readonly class FriendEventPayloadBuilder
{
    private const int ENCODE_FLAGS = \JSON_THROW_ON_ERROR
        | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE;

    public function __construct(
        private RatingUpdater $ratingUpdater,
    ) {
    }

    /** @return array<string, mixed> */
    public function buildFriendRequest(Friendship $friendship, User $from, \DateTimeImmutable $now): array
    {
        return $this->envelope('friend_request', ['friendshipId' => $friendship->getId(), 'from' => $this->buildPlayerRef($from, $now)], $now);
    }

    /** @return array<string, mixed> */
    public function buildFriendAccepted(Friendship $friendship, User $by, \DateTimeImmutable $now): array
    {
        return $this->envelope('friend_accepted', ['friendshipId' => $friendship->getId(), 'by' => $this->buildPlayerRef($by, $now)], $now);
    }

    public function encode(array $payload): string
    {
        return json_encode($payload, self::ENCODE_FLAGS);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    private function envelope(string $event, array $data, \DateTimeImmutable $now): array
    {
        return [
            'type' => 'user.event',
            'event' => $event,
            'notificationUuid' => null,
            'createdAt' => $this->micros($now),
            'unreadCount' => 0,
            'data' => $data,
            'serverTime' => $this->micros($now),
        ];
    }

    /** @return array<string, mixed> */
    private function buildPlayerRef(User $user, \DateTimeImmutable $now): array
    {
        // No time control is attached to a friend event, so there is no
        // real per-category rating to show (06-rating.md sec 5.1 has one
        // pool per SpeedCategory, never a global one). BLITZ is the
        // platform's headline category for this context-free display -
        // same convention as chess.com/lichess.
        $rating = $this->ratingUpdater->currentRating($user, SpeedCategory::BLITZ, $now);

        return [
            'uuid' => $user->getId()->toRfc4122(),
            'username' => $user->getUsername(),
            'rating' => $rating->display(),
            'provisional' => $rating->isProvisional(),
        ];
    }

    private function micros(\DateTimeImmutable $dateTime): int
    {
        return (int) $dateTime->format('Uu');
    }
}
