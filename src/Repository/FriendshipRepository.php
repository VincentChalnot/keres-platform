<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Friendship;
use App\Entity\User;
use App\Model\FriendshipStatus;
use App\Model\Social\Relationship;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Friendship>
 */
class FriendshipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Friendship::class);
    }

    /**
     * The row between `$a` and `$b` in either direction, `FOR UPDATE`
     * (05-social.md sec 3.4). Locks nothing when there are no rows - the
     * genuine simultaneous-create race is caught by the unique index
     * instead (sec 3.4 "Race safety").
     */
    public function findPairForUpdate(User $a, User $b): ?Friendship
    {
        return $this->createQueryBuilder('f')
            ->andWhere('(f.requester = :a AND f.addressee = :b) OR (f.requester = :b AND f.addressee = :a)')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /** Bare existence read, no lock - used for `friendship_not_found` guards. */
    public function findPair(User $a, User $b): ?Friendship
    {
        return $this->createQueryBuilder('f')
            ->andWhere('(f.requester = :a AND f.addressee = :b) OR (f.requester = :b AND f.addressee = :a)')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** `A blocked B`: the row with `requester = $blocker`. */
    public function findBlockRow(User $blocker, User $blocked): ?Friendship
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.requester = :blocker AND f.addressee = :blocked AND f.statusValue = :status')
            ->setParameter('blocker', $blocker)
            ->setParameter('blocked', $blocked)
            ->setParameter('status', FriendshipStatus::BLOCKED->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** 05-social.md sec 4.4/9.1 - a block in either direction between the pair. */
    public function isBlockedEitherWay(User $a, User $b): bool
    {
        return null !== $this->createQueryBuilder('f')
            ->select('1')
            ->andWhere('f.statusValue = :status')
            ->andWhere('(f.requester = :a AND f.addressee = :b) OR (f.requester = :b AND f.addressee = :a)')
            ->setParameter('status', FriendshipStatus::BLOCKED->value)
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * 05-social.md sec 9.1 - one query for a profile page, resolving to
     * exactly one of `none|pending_out|pending_in|friends|blocked_by_me`.
     * Never `blocked_by_them` - that value is never computed for the viewer.
     */
    public function relationOf(User $viewer, User $subject): Relationship
    {
        if ($viewer === $subject) {
            return Relationship::NONE;
        }

        $row = $this->findPair($viewer, $subject);

        if (null === $row) {
            return Relationship::NONE;
        }

        return match ($row->getStatus()) {
            FriendshipStatus::ACCEPTED => Relationship::FRIENDS,
            FriendshipStatus::PENDING => $row->getRequester() === $viewer ? Relationship::PENDING_OUT : Relationship::PENDING_IN,
            FriendshipStatus::DECLINED => $row->getRequester() === $viewer ? Relationship::PENDING_OUT : Relationship::NONE,
            FriendshipStatus::BLOCKED => $row->getRequester() === $viewer ? Relationship::BLOCKED_BY_ME : Relationship::NONE,
        };
    }

    /**
     * `ACCEPTED` friends of `$user`, normalised to "the other side" (sec
     * 3.6), sorted by username for the friends page and settings blocked
     * list.
     *
     * @return list<User>
     */
    public function findAcceptedFriends(User $user): array
    {
        $rows = $this->createQueryBuilder('f')
            ->andWhere('f.statusValue = :status')
            ->andWhere('f.requester = :user OR f.addressee = :user')
            ->setParameter('status', FriendshipStatus::ACCEPTED->value)
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $friends = array_map(static fn (Friendship $f): User => $f->getOther($user), $rows);
        usort($friends, static fn (User $a, User $b): int => strcasecmp($a->getUsername(), $b->getUsername()));

        return $friends;
    }

    /** Rows addressed to `$user`, still `PENDING` - the received-requests inbox. */
    public function findIncomingPending(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.addressee = :user AND f.statusValue = :status')
            ->setParameter('user', $user)
            ->setParameter('status', FriendshipStatus::PENDING->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Rows sent by `$user` still visible as outstanding (sec 3.5: a
     * silently `DECLINED` row still renders as `pending_out`).
     */
    public function findOutgoingPending(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.requester = :user AND f.statusValue IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', [FriendshipStatus::PENDING->value, FriendshipStatus::DECLINED->value])
            ->getQuery()
            ->getResult();
    }

    /** `friendship WHERE requester_id = me AND status = BLOCKED` (sec 9.2) - the only auditable view of a block. */
    public function findBlockedByUser(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.requester = :user AND f.statusValue = :status')
            ->setParameter('user', $user)
            ->setParameter('status', FriendshipStatus::BLOCKED->value)
            ->getQuery()
            ->getResult();
    }
}
