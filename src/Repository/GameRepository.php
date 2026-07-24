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

    /**
     * @return Game[]
     */
    public function findRecentInProgressByOwner(User $owner, int $limit = 5): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.deletedAt IS NULL')
            ->andWhere('g.owner = :owner')
            ->andWhere('g.gameOverAt IS NULL')
            ->setParameter('owner', $owner)
            ->orderBy('g.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countInProgressByOwner(User $owner): int
    {
        return (int) $this->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->andWhere('g.deletedAt IS NULL')
            ->andWhere('g.owner = :owner')
            ->andWhere('g.gameOverAt IS NULL')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array{wins: int, losses: int, draws: int}
     */
    public function getFinishedGameStatsForOwner(User $owner): array
    {
        $rows = $this->createQueryBuilder('g')
            ->select('g.isWhite AS isWhite', 'g.whiteWins AS whiteWins', 'g.draw AS draw')
            ->andWhere('g.deletedAt IS NULL')
            ->andWhere('g.owner = :owner')
            ->andWhere('g.gameOverAt IS NOT NULL')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getArrayResult();

        $stats = ['wins' => 0, 'losses' => 0, 'draws' => 0];

        foreach ($rows as $row) {
            if ($row['draw']) {
                ++$stats['draws'];
            } elseif ($row['whiteWins'] === $row['isWhite']) {
                ++$stats['wins'];
            } else {
                ++$stats['losses'];
            }
        }

        return $stats;
    }
}
