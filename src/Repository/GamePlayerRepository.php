<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;
use App\Entity\GamePlayer;
use App\Entity\User;
use App\Model\PieceColor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GamePlayer>
 */
class GamePlayerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GamePlayer::class);
    }

    public function findOneByGameAndUser(Game $game, User $user): ?GamePlayer
    {
        return $this->createQueryBuilder('gp')
            ->andWhere('gp.game = :game')
            ->andWhere('gp.user = :user')
            ->setParameter('game', $game)
            ->setParameter('user', $user)
            ->orderBy('gp.colorValue', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByGameAndColor(Game $game, PieceColor $color): ?GamePlayer
    {
        return $this->createQueryBuilder('gp')
            ->andWhere('gp.game = :game')
            ->andWhere('gp.colorValue = :color')
            ->setParameter('game', $game)
            ->setParameter('color', $color->value)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
