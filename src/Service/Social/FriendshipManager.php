<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Friendship;
use App\Entity\User;
use App\Exception\CannotBlockSelfException;
use App\Exception\CannotRequestSelfException;
use App\Exception\FriendshipBlockedException;
use App\Exception\FriendshipExistsException;
use App\Exception\FriendshipNotFoundException;
use App\Model\FriendshipStatus;
use App\Model\MultiplayerLimits;
use App\Model\Social\FriendRequestOutcome;
use App\Model\Social\Relationship;
use App\Repository\FriendshipRepository;
use App\Service\Game\GameUpdatePublisher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The write side of the friendship/block state machine (05-social.md sec
 * 3-4). One row per unordered pair outside `BLOCKED` (F1, enforced by
 * `uniq_friendship_active_pair`); `BLOCKED` rows are strictly directional
 * (F2). `readonly`, no mutable state, safe under FrankenPHP worker mode.
 * SSE publishing to `user/{uuid}` always happens after commit
 * (02-realtime.md sec 7.1) - never inside the transactional closures below.
 */
final readonly class FriendshipManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private FriendshipRepository $friendshipRepository,
        private GameUpdatePublisher $publisher,
        private FriendEventPayloadBuilder $payloadBuilder,
    ) {
    }

    /**
     * `POST /friends/request` (sec 3.3 T1/T2/T7/T8, sec 3.4 crossing
     * requests, sec 4.2 blocking). Guard order matches
     * `09-api-reference.md` sec 4.3: self, then blocked, then the
     * transaction.
     *
     * Blocking asymmetry (sec 4.2/4.3): a block is never disclosed to the
     * blocked party. When the *caller* blocked the target, the request is
     * refused with a visible 403 (the caller already knows about their own
     * block). When the *target* blocked the caller, the response must be
     * byte-identical to a genuine success - so this returns a "fake"
     * `pending(false)` outcome and writes nothing, rather than throwing.
     */
    public function request(User $requester, User $addressee, \DateTimeImmutable $now): FriendRequestOutcome
    {
        if ($requester === $addressee) {
            throw new CannotRequestSelfException();
        }

        if (null !== $this->friendshipRepository->findBlockRow($requester, $addressee)) {
            throw new FriendshipBlockedException();
        }

        if (null !== $this->friendshipRepository->findBlockRow($addressee, $requester)) {
            return FriendRequestOutcome::pending(false);
        }

        try {
            [$outcome, $notify] = $this->doRequest($requester, $addressee, $now);
        } catch (UniqueConstraintViolationException) {
            // sec 3.4 "Race safety": two simultaneous first-ever requests both
            // locked nothing and both tried to INSERT; the loser's INSERT hit
            // `uniq_friendship_active_pair`. One retry now sees the winner's
            // row and takes the T2 (crossing-request auto-accept) branch.
            [$outcome, $notify] = $this->doRequest($requester, $addressee, $now);
        }

        $this->dispatchNotify($notify, $now);

        return $outcome;
    }

    /**
     * `POST /friends/{username}/accept` (T3). No-op (200) if already
     * `ACCEPTED` (09-api-reference.md sec 4.3 item 3).
     *
     * @throws FriendshipNotFoundException no row addressed to `$addressee`, or the row is not a live `PENDING`/`ACCEPTED` state
     */
    public function accept(User $addressee, User $requester, \DateTimeImmutable $now): void
    {
        $accepted = null;

        $this->entityManager->wrapInTransaction(function () use ($addressee, $requester, $now, &$accepted): void {
            $row = $this->friendshipRepository->findPairForUpdate($addressee, $requester);

            if (null === $row || $row->getAddressee() !== $addressee) {
                throw new FriendshipNotFoundException();
            }

            if (FriendshipStatus::ACCEPTED === $row->getStatus()) {
                return;
            }

            if (FriendshipStatus::PENDING !== $row->getStatus()) {
                throw new FriendshipNotFoundException();
            }

            $row->transitionTo(FriendshipStatus::ACCEPTED, $now);
            $this->entityManager->flush();
            $accepted = $row;
        });

        if (null !== $accepted) {
            // sec 3.3 T3: only the requester is notified - the acceptor already knows.
            $this->publisher->publishUserEvent(
                $requester->getId()->toRfc4122(),
                $this->payloadBuilder->encode($this->payloadBuilder->buildFriendAccepted($accepted, $addressee, $now)),
            );
        }
    }

    /**
     * `POST /friends/{username}/decline` (T4). No-op (200) if already
     * `DECLINED`. No notification - a decline is invisible to the
     * requester by design (sec 3.5).
     *
     * @throws FriendshipNotFoundException no row addressed to `$addressee` in a live `PENDING`/`DECLINED` state
     */
    public function decline(User $addressee, User $requester, \DateTimeImmutable $now): void
    {
        $this->entityManager->wrapInTransaction(function () use ($addressee, $requester, $now): void {
            $row = $this->friendshipRepository->findPairForUpdate($addressee, $requester);

            if (null === $row || $row->getAddressee() !== $addressee) {
                throw new FriendshipNotFoundException();
            }

            if (FriendshipStatus::DECLINED === $row->getStatus()) {
                return;
            }

            if (FriendshipStatus::PENDING !== $row->getStatus()) {
                throw new FriendshipNotFoundException();
            }

            $row->transitionTo(FriendshipStatus::DECLINED, $now);
            $this->entityManager->flush();
        });
    }

    /**
     * `POST /friends/{username}/remove` (T5 by the requester on a `PENDING`
     * row, T6 by either party on `ACCEPTED`). Deleting an already-gone row
     * is treated by the caller as success (09-api-reference.md sec 4.3
     * item 2's "second call returns 404, client treats as success"). No
     * notification either way (sec 3.3 T6: "an unfriend notification is an
     * insult delivery mechanism").
     *
     * @throws FriendshipNotFoundException no live row between the pair, or a `PENDING` row removed by the addressee (only the requester may cancel a pending request)
     */
    public function remove(User $me, User $other): void
    {
        $this->entityManager->wrapInTransaction(function () use ($me, $other): void {
            $row = $this->friendshipRepository->findPairForUpdate($me, $other);

            if (null === $row) {
                throw new FriendshipNotFoundException();
            }

            $status = $row->getStatus();

            if (FriendshipStatus::ACCEPTED === $status || (FriendshipStatus::PENDING === $status && $row->getRequester() === $me)) {
                $this->entityManager->remove($row);
                $this->entityManager->flush();

                return;
            }

            throw new FriendshipNotFoundException();
        });
    }

    /**
     * `POST /friends/block` (T9, sec 4.5). Drops any live relation in
     * either direction (F2) and upserts the caller's directional block; a
     * mirrored block already owned by `$blocked` is left untouched. No
     * Mercure event is published to the blocked user (sec 4.3/4.5).
     */
    public function block(User $blocker, User $blocked, \DateTimeImmutable $now): void
    {
        if ($blocker === $blocked) {
            throw new CannotBlockSelfException();
        }

        $this->connection->transactional(static function (Connection $conn) use ($blocker, $blocked, $now): void {
            $blockerId = $blocker->getId()->toRfc4122();
            $blockedId = $blocked->getId()->toRfc4122();

            $conn->fetchAllAssociative(
                'SELECT id FROM friendship WHERE (requester_id, addressee_id) IN ((:me,:them),(:them,:me)) FOR UPDATE',
                ['me' => $blockerId, 'them' => $blockedId],
            );

            $conn->executeStatement(
                'DELETE FROM friendship WHERE status_value <> :blocked AND (requester_id, addressee_id) IN ((:me,:them),(:them,:me))',
                ['blocked' => FriendshipStatus::BLOCKED->value, 'me' => $blockerId, 'them' => $blockedId],
            );

            $conn->executeStatement(
                <<<'SQL'
                    INSERT INTO friendship (requester_id, addressee_id, status_value, created_at, responded_at)
                    VALUES (:me, :them, :blocked, :now, :now)
                    ON CONFLICT (requester_id, addressee_id)
                    DO UPDATE SET status_value = :blocked, responded_at = :now
                    SQL,
                ['me' => $blockerId, 'them' => $blockedId, 'blocked' => FriendshipStatus::BLOCKED->value, 'now' => $now->format('Y-m-d H:i:sP')],
            );
        });

        // ORM-side rows for this pair are now stale (raw SQL bypassed the
        // UnitOfWork identity map); the caller's next read goes through the
        // repository, not a cached entity, so this only needs to clear
        // anything already hydrated in *this* request.
        $this->entityManager->clear(Friendship::class);
    }

    /**
     * `POST /friends/{username}/unblock` (T10). Deletes only the row where
     * `$blocker` is `requester` - never restores a prior friendship.
     *
     * @throws FriendshipNotFoundException no `BLOCKED` row with `$blocker` as `requester`
     */
    public function unblock(User $blocker, User $blocked): void
    {
        $row = $this->friendshipRepository->findBlockRow($blocker, $blocked);

        if (null === $row) {
            throw new FriendshipNotFoundException();
        }

        $this->entityManager->remove($row);
        $this->entityManager->flush();
    }

    public function isBlockedEitherWay(User $a, User $b): bool
    {
        return $this->friendshipRepository->isBlockedEitherWay($a, $b);
    }

    public function relationOf(User $viewer, User $subject): Relationship
    {
        return $this->friendshipRepository->relationOf($viewer, $subject);
    }

    /** @return array{0: FriendRequestOutcome, 1: ?array{event: string, friendship: Friendship, from: User, to: User}} */
    private function doRequest(User $requester, User $addressee, \DateTimeImmutable $now): array
    {
        $notify = null;

        $outcome = $this->entityManager->wrapInTransaction(
            function () use ($requester, $addressee, $now, &$notify): FriendRequestOutcome {
                $existing = $this->friendshipRepository->findPairForUpdate($requester, $addressee);

                if (null === $existing) {
                    $friendship = new Friendship($requester, $addressee, FriendshipStatus::PENDING, $now);
                    $this->entityManager->persist($friendship);
                    $this->entityManager->flush();

                    $notify = ['event' => 'friend_request', 'friendship' => $friendship, 'from' => $requester, 'to' => $addressee];

                    return FriendRequestOutcome::pending(true);
                }

                return match ($existing->getStatus()) {
                    FriendshipStatus::ACCEPTED => throw new FriendshipExistsException(),
                    // A residual BLOCKED row here is a benign race against the
                    // pre-check above (sec 4.3 non-disclosure default: never
                    // surface it as anything other than a normal outcome).
                    FriendshipStatus::BLOCKED => FriendRequestOutcome::pending(false),
                    FriendshipStatus::PENDING => $this->onPending($existing, $requester, $now, $notify),
                    FriendshipStatus::DECLINED => $this->onDeclined($existing, $requester, $now, $notify),
                };
            },
        );

        return [$outcome, $notify];
    }

    /**
     * T2 (crossing request, auto-accept) or T1-idempotent no-op (existing
     * item 5). `$notify` is set by reference for the caller to publish
     * post-commit.
     *
     * @param ?array{event: string, friendship: Friendship, from: User, to: User} $notify
     */
    private function onPending(Friendship $existing, User $requester, \DateTimeImmutable $now, ?array &$notify): FriendRequestOutcome
    {
        if ($existing->getRequester() === $requester) {
            return FriendRequestOutcome::pending(false);
        }

        $existing->transitionTo(FriendshipStatus::ACCEPTED, $now);
        $this->entityManager->flush();
        $notify = ['event' => 'friend_accepted_both', 'friendship' => $existing, 'from' => $existing->getRequester(), 'to' => $existing->getAddressee()];

        return FriendRequestOutcome::accepted();
    }

    /**
     * T7 (re-request past cooldown) / T8 (no-op inside cooldown) when the
     * caller is the original requester; a generalisation of the crossing-
     * request logic (T2) when the caller is the party who originally
     * declined - requesting now is unambiguous consent, so it auto-accepts
     * rather than resetting to `PENDING` and waiting on the other side again.
     *
     * @param ?array{event: string, friendship: Friendship, from: User, to: User} $notify
     */
    private function onDeclined(Friendship $existing, User $requester, \DateTimeImmutable $now, ?array &$notify): FriendRequestOutcome
    {
        if ($existing->getRequester() !== $requester) {
            $existing->transitionTo(FriendshipStatus::ACCEPTED, $now);
            $this->entityManager->flush();
            $notify = ['event' => 'friend_accepted_both', 'friendship' => $existing, 'from' => $existing->getRequester(), 'to' => $existing->getAddressee()];

            return FriendRequestOutcome::accepted();
        }

        $respondedAt = $existing->getRespondedAt();
        $elapsed = null === $respondedAt ? \PHP_INT_MAX : $now->getTimestamp() - $respondedAt->getTimestamp();

        if ($elapsed < MultiplayerLimits::FRIEND_REQUEST_COOLDOWN_SECONDS) {
            return FriendRequestOutcome::pending(false); // T8
        }

        $existing->transitionTo(FriendshipStatus::PENDING, null, $now); // T7
        $this->entityManager->flush();
        $notify = ['event' => 'friend_request', 'friendship' => $existing, 'from' => $existing->getRequester(), 'to' => $existing->getAddressee()];

        return FriendRequestOutcome::pending(false);
    }

    /** @param ?array{event: string, friendship: Friendship, from: User, to: User} $notify */
    private function dispatchNotify(?array $notify, \DateTimeImmutable $now): void
    {
        if (null === $notify) {
            return;
        }

        if ('friend_accepted_both' === $notify['event']) {
            // T2/crossing-declined-request: both parties get FRIEND_ACCEPTED (sec 3.3 T2).
            $this->publisher->publishUserEvent(
                $notify['from']->getId()->toRfc4122(),
                $this->payloadBuilder->encode($this->payloadBuilder->buildFriendAccepted($notify['friendship'], $notify['to'], $now)),
            );
            $this->publisher->publishUserEvent(
                $notify['to']->getId()->toRfc4122(),
                $this->payloadBuilder->encode($this->payloadBuilder->buildFriendAccepted($notify['friendship'], $notify['from'], $now)),
            );

            return;
        }

        // friend_request: only the addressee is notified.
        $this->publisher->publishUserEvent(
            $notify['to']->getId()->toRfc4122(),
            $this->payloadBuilder->encode($this->payloadBuilder->buildFriendRequest($notify['friendship'], $notify['from'], $now)),
        );
    }
}
