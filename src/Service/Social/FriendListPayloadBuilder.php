<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Friendship;
use App\Entity\User;
use App\Model\Glicko\Rating;
use App\Model\Social\RatingSummary;
use App\Repository\FriendshipRepository;
use App\Service\Rating\RatingUpdater;

/**
 * The `{friends, incoming, outgoing, blocked}` shape shared by
 * `GET /friends/list` (JSON) and `GET /friends` (the same shape, embedded
 * as the page's bootstrap script tag) - one builder so the two can never
 * drift, the same discipline `SeekPayloadBuilder` applies for the lobby
 * (05-social.md sec 3.6/9.2, 09-api-reference.md sec 4.3).
 */
final readonly class FriendListPayloadBuilder
{
    public function __construct(
        private FriendshipRepository $friendshipRepository,
        private RatingUpdater $ratingUpdater,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(User $user, \DateTimeImmutable $now): array
    {
        return [
            'friends' => array_map(
                fn (User $friend): array => $this->buildFriend($friend, $now),
                $this->friendshipRepository->findAcceptedFriends($user),
            ),
            'incoming' => array_map(
                fn (Friendship $f): array => $this->buildRequest($f->getRequester(), $f->getCreatedAt()),
                $this->friendshipRepository->findIncomingPending($user),
            ),
            'outgoing' => array_map(
                fn (Friendship $f): array => $this->buildRequest($f->getAddressee(), $f->getCreatedAt()),
                $this->friendshipRepository->findOutgoingPending($user),
            ),
            'blocked' => array_map(
                fn (Friendship $f): array => $this->buildRequest($f->getAddressee(), $f->getRespondedAt()),
                $this->friendshipRepository->findBlockedByUser($user),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function buildFriend(User $friend, \DateTimeImmutable $now): array
    {
        return [
            'username' => $friend->getUsername(),
            'displayName' => $friend->getDisplayName(),
            'avatarUrl' => $friend->getAvatarUrl(),
            'online' => $friend->isOnline($now),
            'lastSeenAt' => $friend->getLastSeenAt()?->format(\DateTimeInterface::ATOM),
            'ratings' => array_map(
                static fn (Rating $rating): array => RatingSummary::fromRating($rating)->toArray(),
                $this->ratingUpdater->currentRatingsForAllCategories($friend, $now),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function buildRequest(User $other, ?\DateTimeImmutable $createdAt = null): array
    {
        return [
            'username' => $other->getUsername(),
            'displayName' => $other->getDisplayName(),
            'avatarUrl' => $other->getAvatarUrl(),
            'createdAt' => $createdAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
