<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function usernameExists(string $username): bool
    {
        return null !== $this->createQueryBuilder('u')
            ->select('1')
            ->andWhere('LOWER(u.username) = LOWER(:username)')
            ->setParameter('username', $username)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByValidResetTokenHash(string $tokenHash): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.resetToken = :tokenHash')
            ->andWhere('u.resetTokenExpiresAt > :now')
            ->setParameter('tokenHash', $tokenHash)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** `GET /@/{username}` (05-social.md sec 9.1), served by `uniq_user_username_lower`. */
    public function findOneByUsernameFold(string $username): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.username) = LOWER(:username)')
            ->setParameter('username', $username)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * `GET /players/search` (05-social.md sec 2.2). Prefix match on
     * `LOWER(username)`, `$viewer` excluded, any user blocked either way
     * excluded, and `UserPreferences.searchableByOtherUsers` (default true
     * when no preferences row exists yet) honoured.
     *
     * @return list<User>
     */
    public function searchByUsernamePrefix(string $prefix, User $viewer, int $limit): array
    {
        $escaped = addcslashes($prefix, '%_\\');

        return $this->createQueryBuilder('u')
            ->andWhere('u.id <> :viewerId')
            ->andWhere('LOWER(u.username) LIKE LOWER(:prefix) ESCAPE \'\\\'')
            ->andWhere('NOT EXISTS (
                SELECT 1 FROM App\Entity\Friendship f
                 WHERE f.statusValue = 3
                   AND ((f.requester = :viewer AND f.addressee = u) OR (f.requester = u AND f.addressee = :viewer))
            )')
            ->andWhere('NOT EXISTS (
                SELECT 1 FROM App\Entity\UserPreferences up
                 WHERE up.user = u AND up.searchableByOtherUsers = false
            )')
            ->setParameter('viewerId', $viewer->getId())
            ->setParameter('viewer', $viewer)
            ->setParameter('prefix', $escaped.'%')
            ->orderBy('LOWER(u.username)', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
