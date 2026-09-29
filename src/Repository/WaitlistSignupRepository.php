<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WaitlistSignup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WaitlistSignup>
 */
class WaitlistSignupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WaitlistSignup::class);
    }

    /** Same "hash lookup, not-yet-expired" shape as `UserRepository::findByValidResetTokenHash`. */
    public function findByValidTokenHash(string $tokenHash): ?WaitlistSignup
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.tokenHash = :tokenHash')
            ->andWhere('w.expiresAt > :now')
            ->andWhere('w.confirmedAt IS NULL')
            ->setParameter('tokenHash', $tokenHash)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }
}
