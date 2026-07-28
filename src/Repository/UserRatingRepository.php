<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserRating;
use App\Model\MultiplayerLimits;
use App\Model\SpeedCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserRating>
 */
class UserRatingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserRating::class);
    }

    /** `uniq_user_rating_user_category` - bare read, no lock (06-rating.md sec 5.3: reads never insert). */
    public function findOneFor(User $user, SpeedCategory $category): ?UserRating
    {
        return $this->createQueryBuilder('ur')
            ->andWhere('ur.user = :user')
            ->andWhere('ur.categoryValue = :category')
            ->setParameter('user', $user)
            ->setParameter('category', $category->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Every pool this user has a row in, ordered by category - profile page. */
    public function findAllFor(User $user): array
    {
        return $this->createQueryBuilder('ur')
            ->andWhere('ur.user = :user')
            ->setParameter('user', $user)
            ->orderBy('ur.categoryValue', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** `idx_user_rating_leaderboard`, Pagerfanta-ready. */
    public function createLeaderboardQueryBuilder(SpeedCategory $category): QueryBuilder
    {
        return $this->createQueryBuilder('ur')
            ->addSelect('u')
            ->join('ur.user', 'u')
            ->andWhere('ur.categoryValue = :category')
            ->andWhere('ur.gamesPlayed >= :minGames')
            ->andWhere('ur.lastRatedAt > :recencyThreshold')
            ->setParameter('category', $category->value)
            ->setParameter('minGames', MultiplayerLimits::LEADERBOARD_MIN_GAMES)
            ->setParameter('recencyThreshold', new \DateTimeImmutable(\sprintf('-%d days', MultiplayerLimits::LEADERBOARD_ACTIVE_DAYS)))
            ->orderBy('ur.rating', 'DESC');
    }

    /**
     * `06-rating.md` sec 5.3/5.4: both rows MUST already exist (the caller's
     * `INSERT ... ON CONFLICT DO NOTHING` upsert runs first) - this only
     * locks. Ordered by `user_id` ASC, the suffix of the global lock order,
     * so two games between the same pair finishing concurrently cannot
     * deadlock.
     *
     * @param list<User> $users
     *
     * @return UserRating[]
     */
    public function lockForUpdate(array $users, SpeedCategory $category): array
    {
        return $this->createQueryBuilder('ur')
            ->andWhere('ur.user IN (:users)')
            ->andWhere('ur.categoryValue = :category')
            ->setParameter('users', $users)
            ->setParameter('category', $category->value)
            ->orderBy('ur.user', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }
}
