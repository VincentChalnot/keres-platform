<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;
use App\Entity\User;
use App\Model\GameEndReason;
use App\Model\OpponentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    /** `GameListAction` (04-matchmaking.md sec 9.2) - paginated with Pagerfanta, per 00-overview.md sec 6. */
    public function queryOngoingForUser(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('g')
            ->join('g.players', 'p')
            ->andWhere('p.user = :user')
            ->andWhere('p.hiddenAt IS NULL')
            ->andWhere('g.deletedAt IS NULL')
            ->andWhere('g.gameOverAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('g.createdAt', 'DESC');
    }

    /** @see self::queryOngoingForUser() */
    public function queryFinishedForUser(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('g')
            ->join('g.players', 'p')
            ->andWhere('p.user = :user')
            ->andWhere('p.hiddenAt IS NULL')
            ->andWhere('g.deletedAt IS NULL')
            ->andWhere('g.gameOverAt IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('g.createdAt', 'DESC');
    }

    /**
     * @return Game[]
     */
    public function findRecentInProgressForUser(User $user, int $limit = 5): array
    {
        return $this->createQueryBuilder('g')
            ->join('g.players', 'p')
            ->andWhere('p.user = :user')
            ->andWhere('p.hiddenAt IS NULL')
            ->andWhere('g.deletedAt IS NULL')
            ->andWhere('g.gameOverAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('g.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countInProgressForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('g')
            ->select('COUNT(DISTINCT g.id)')
            ->join('g.players', 'p')
            ->andWhere('p.user = :user')
            ->andWhere('p.hiddenAt IS NULL')
            ->andWhere('g.deletedAt IS NULL')
            ->andWhere('g.gameOverAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Win/lose/draw distribution for the user's finished games. Hot-seat
     * games are excluded from the win/lose split (the user holds both
     * `GamePlayer` rows, so a naive colour comparison would double-count
     * every result as both a win and a loss - the same trap documented on
     * `AdminStatsRepository::getUserStats()`) but still counted as draws
     * consistently with that method. Aborted games (03-time-control.md sec
     * 7.3: `whiteWins = false, draw = false`, i.e. no result at all) are
     * excluded outright - `draw = false` alone would otherwise silently
     * misattribute every abort as a decisive win/loss.
     *
     * @return array{wins: int, losses: int, draws: int}
     */
    public function getFinishedGameStatsForUser(User $user): array
    {
        $row = $this->getEntityManager()->createQuery(
            'SELECT
                SUM(CASE WHEN g.draw = false AND g.opponentTypeValue <> 1
                    AND ((p.colorValue = 0 AND g.whiteWins = true) OR (p.colorValue = 1 AND g.whiteWins = false)) THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN g.draw = false AND g.opponentTypeValue <> 1
                    AND ((p.colorValue = 0 AND g.whiteWins = false) OR (p.colorValue = 1 AND g.whiteWins = true)) THEN 1 ELSE 0 END) AS losses,
                SUM(CASE WHEN g.draw = true THEN 1 ELSE 0 END) AS draws
             FROM App\Entity\GamePlayer p
             JOIN p.game g
             WHERE p.user = :user AND p.hiddenAt IS NULL AND g.deletedAt IS NULL
                 AND g.gameOverAt IS NOT NULL AND g.endReasonValue <> :aborted'
        )->setParameter('user', $user)->setParameter('aborted', GameEndReason::ABORTED->value)->getSingleResult();

        return [
            'wins' => (int) ($row['wins'] ?? 0),
            'losses' => (int) ($row['losses'] ?? 0),
            'draws' => (int) ($row['draws'] ?? 0),
        ];
    }

    /**
     * Profile game history (05-social.md sec 9.3, 09-api-reference.md sec
     * 4.6) - `ProfilePageAction` and `ProfileGamesAction`. Pagerfanta-ready,
     * mirrors queryFinishedForUser()'s shape.
     *
     * `$includeAllOpponentTypes` lifts the `opponentTypeValue = MULTIPLAYER`
     * predicate: AI/hot-seat games are participant-only (contract sec 4.3),
     * so the public view lists HUMAN opponents only; self view drops the
     * filter (sec 9.3 third bullet, sec 11 open question 7).
     *
     * `$includeHidden` lifts the `hiddenAt IS NULL` predicate - self view
     * only, gated by the caller (09-api-reference.md sec 4.6 item 2).
     */
    public function queryProfileGamesForUser(User $subject, bool $includeAllOpponentTypes = false, bool $includeHidden = false): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('g')
            ->join('g.players', 'p')
            ->andWhere('p.user = :subject')
            ->andWhere('g.deletedAt IS NULL')
            ->setParameter('subject', $subject)
            ->orderBy('g.createdAt', 'DESC');

        if (!$includeHidden) {
            $queryBuilder->andWhere('p.hiddenAt IS NULL');
        }

        if (!$includeAllOpponentTypes) {
            $queryBuilder->andWhere('g.opponentTypeValue = :opponentType')
                ->setParameter('opponentType', OpponentType::MULTIPLAYER->value);
        }

        return $queryBuilder;
    }
}
