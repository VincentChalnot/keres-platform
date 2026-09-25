<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use App\Model\Notification\NotificationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    /** The badge is capped (07-notifications.md sec 7.3): nobody needs an exact 3,000. */
    public const int UNREAD_COUNT_CAP = 100;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function findOneForUser(User $user, Uuid $uuid): ?Notification
    {
        return $this->findOneBy(['user' => $user, 'uuid' => $uuid]);
    }

    /** @return list<Notification> newest first */
    public function findLatestForUser(User $user, int $limit, int $offset = 0): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Index-only over `idx_notification_inbox`, capped at `UNREAD_COUNT_CAP`. */
    public function countUnread(User $user): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT count(*) FROM (SELECT 1 FROM notification WHERE user_id = :user AND read_at IS NULL LIMIT :cap) capped',
            ['user' => $user->getId()->toRfc4122(), 'cap' => self::UNREAD_COUNT_CAP],
        );
    }

    public function findUnreadBySubject(User $user, NotificationType $type, string $subject): ?Notification
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.user = :user')
            ->andWhere('n.type = :type')
            ->andWhere('n.subject = :subject')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('type', $type)
            ->setParameter('subject', $subject)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** One `UPDATE ... WHERE user_id = :u AND read_at IS NULL` (sec 7.4); returns the number of rows marked. */
    public function markAllRead(User $user, \DateTimeImmutable $now, ?string $subject = null): int
    {
        $qb = $this->createQueryBuilder('n')
            ->update()
            ->set('n.readAt', ':now')
            ->andWhere('n.user = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('user', $user);

        if (null !== $subject) {
            $qb->andWhere('n.subject = :subject')->setParameter('subject', $subject);
        }

        return (int) $qb->getQuery()->execute();
    }
}
