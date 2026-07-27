<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Game>
 */
class GameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Game::class);
    }

    public function findByUuid(Uuid $uuid): ?Game
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.uuid = :uuid')
            ->andWhere('g.deletedAt IS NULL')
            ->setParameter('uuid', $uuid, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findAnyByUuid(Uuid $uuid): ?Game
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.uuid = :uuid')
            ->setParameter('uuid', $uuid, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForPlay(Uuid $uuid): ?Game
    {
        return $this->createQueryBuilder('g')
            ->addSelect('p', 'pu')
            ->leftJoin('g.players', 'p')
            ->leftJoin('p.user', 'pu')
            ->andWhere('g.uuid = :uuid')
            ->andWhere('g.deletedAt IS NULL')
            ->setParameter('uuid', $uuid, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Game[]
     */
    public function findOngoingForUser(User $user): array
    {
        return $this->createQueryBuilder('g')
            ->join('g.players', 'p')
            ->andWhere('p.user = :user')
            ->andWhere('p.hiddenAt IS NULL')
            ->andWhere('g.deletedAt IS NULL')
            ->andWhere('g.gameOverAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('g.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Game[]
     */
    public function findFinishedForUser(User $user): array
    {
        return $this->createQueryBuilder('g')
            ->join('g.players', 'p')
            ->andWhere('p.user = :user')
            ->andWhere('p.hiddenAt IS NULL')
            ->andWhere('g.deletedAt IS NULL')
            ->andWhere('g.gameOverAt IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('g.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
