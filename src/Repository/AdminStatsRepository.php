<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;
use App\Entity\Move;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Admin-only aggregate queries spanning multiple entities (User, Game,
 * GameMove, Move, BoardPosition). Deliberately NOT a Doctrine
 * ServiceEntityRepository (it isn't bound to one entity) and kept out of
 * GameRepository/UserRepository to avoid bloating those with admin-only
 * reporting queries.
 */
class AdminStatsRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getTotalPlayers(): int
    {
        return (int) $this->entityManager->createQuery(
            'SELECT COUNT(u.id) FROM App\Entity\User u'
        )->getSingleScalarResult();
    }

    public function getConnectedPlayersCount(\DateTimeImmutable $since): int
    {
        return (int) $this->entityManager->createQuery(
            'SELECT COUNT(DISTINCT g.owner)
             FROM App\Entity\GameMove gm
             JOIN gm.game g
             WHERE gm.createdAt > :since'
        )->setParameter('since', $since)->getSingleScalarResult();
    }

    /**
     * Win/lose/draw distribution across every finished game (AI + hotseat),
     * resolved from the owner's perspective.
     *
     * @return array{win: int, lose: int, draw: int}
     */
    public function getOutcomeDistribution(): array
    {
        $row = $this->entityManager->createQuery(
            'SELECT
                SUM(CASE WHEN g.draw = false AND ((g.isWhite = true AND g.whiteWins = true) OR (g.isWhite = false AND g.whiteWins = false)) THEN 1 ELSE 0 END) AS winCount,
                SUM(CASE WHEN g.draw = false AND ((g.isWhite = true AND g.whiteWins = false) OR (g.isWhite = false AND g.whiteWins = true)) THEN 1 ELSE 0 END) AS loseCount,
                SUM(CASE WHEN g.draw = true THEN 1 ELSE 0 END) AS drawCount
             FROM App\Entity\Game g
             WHERE g.gameOverAt IS NOT NULL'
        )->getSingleResult();

        return [
            'win' => (int) ($row['winCount'] ?? 0),
            'lose' => (int) ($row['loseCount'] ?? 0),
            'draw' => (int) ($row['drawCount'] ?? 0),
        ];
    }

    /**
     * Histogram of move counts across every game (finished or not).
     *
     * @return array<string, int> bucket label => number of games in that bucket
     */
    public function getMoveCountDistribution(): array
    {
        $rows = $this->entityManager->createQuery(
            'SELECT COUNT(gm.id) AS moveCount
             FROM App\Entity\Game g
             LEFT JOIN g.gameMoves gm
             WHERE g.deletedAt IS NULL
             GROUP BY g.id'
        )->getArrayResult();

        $buckets = [
            '0-10' => 0,
            '11-20' => 0,
            '21-30' => 0,
            '31-40' => 0,
            '41-50' => 0,
            '51+' => 0,
        ];

        foreach ($rows as $row) {
            $count = (int) $row['moveCount'];
            $bucket = match (true) {
                $count <= 10 => '0-10',
                $count <= 20 => '11-20',
                $count <= 30 => '21-30',
                $count <= 40 => '31-40',
                $count <= 50 => '41-50',
                default => '51+',
            };
            ++$buckets[$bucket];
        }

        return $buckets;
    }

    /**
     * Games with no activity (last move, or creation if move-less) in the
     * last 24 hours and not yet over. The 24h threshold is intentionally
     * hardcoded (no config knob), per spec.
     *
     * @return Game[]
     */
    public function getStaleGames(): array
    {
        $threshold = new \DateTimeImmutable('-24 hours');

        return $this->entityManager->createQuery(
            'SELECT g
             FROM App\Entity\Game g
             LEFT JOIN g.gameMoves gm
             WHERE g.gameOverAt IS NULL AND g.deletedAt IS NULL
             GROUP BY g.id
             HAVING (MAX(gm.createdAt) IS NULL AND MIN(g.createdAt) < :threshold)
                 OR MAX(gm.createdAt) < :threshold
             ORDER BY MAX(gm.createdAt) ASC'
        )->setParameter('threshold', $threshold)->getResult();
    }

    /**
     * @return array{gamesCount: int, winCount: int, loseCount: int, drawCount: int, lastMoveAt: ?\DateTimeImmutable}
     */
    public function getUserStats(User $user): array
    {
        $row = $this->entityManager->createQuery(
            'SELECT
                COUNT(g.id) AS gamesCount,
                SUM(CASE WHEN g.gameOverAt IS NOT NULL AND g.draw = false AND ((g.isWhite = true AND g.whiteWins = true) OR (g.isWhite = false AND g.whiteWins = false)) THEN 1 ELSE 0 END) AS winCount,
                SUM(CASE WHEN g.gameOverAt IS NOT NULL AND g.draw = false AND ((g.isWhite = true AND g.whiteWins = false) OR (g.isWhite = false AND g.whiteWins = true)) THEN 1 ELSE 0 END) AS loseCount,
                SUM(CASE WHEN g.draw = true THEN 1 ELSE 0 END) AS drawCount
             FROM App\Entity\Game g
             WHERE g.owner = :user AND g.deletedAt IS NULL'
        )->setParameter('user', $user)->getSingleResult();

        $lastMoveAt = $this->entityManager->createQuery(
            'SELECT MAX(gm.createdAt)
             FROM App\Entity\GameMove gm
             JOIN gm.game g
             WHERE g.owner = :user'
        )->setParameter('user', $user)->getSingleScalarResult();

        return [
            'gamesCount' => (int) ($row['gamesCount'] ?? 0),
            'winCount' => (int) ($row['winCount'] ?? 0),
            'loseCount' => (int) ($row['loseCount'] ?? 0),
            'drawCount' => (int) ($row['drawCount'] ?? 0),
            'lastMoveAt' => $lastMoveAt ? new \DateTimeImmutable($lastMoveAt) : null,
        ];
    }

    /**
     * @return Game[]
     */
    public function getUserGames(User $user): array
    {
        return $this->entityManager->getRepository(Game::class)->createQueryBuilder('g')
            ->andWhere('g.owner = :user')
            ->andWhere('g.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('g.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every game's first move collapses to the same deduplicated
     * BoardPosition (see BoardTreeManager). Resolves it without hardcoding
     * any game-rule knowledge of what the starting layout looks like.
     */
    public function getRootBoardPositionId(): ?int
    {
        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT DISTINCT bp.id
             FROM board_position bp
             JOIN move m ON m.from_board_position_id = bp.id
             JOIN game_move gm ON gm.move_id = m.id
             WHERE gm.id IN (SELECT MIN(id) FROM game_move GROUP BY game_id)'
        )->fetchAllAssociative();

        if ([] === $rows) {
            return null;
        }

        if (\count($rows) > 1) {
            throw new \RuntimeException(\sprintf('Expected exactly one root board position across all games, found %d — data anomaly.', \count($rows)));
        }

        return (int) $rows[0]['id'];
    }

    /**
     * Immediate child moves of a board position, annotated with a global
     * popularity count (number of GameMove rows referencing each Move).
     *
     * @return array<int, array{moveData: string, toBoardPositionId: int, toBoardPositionData: string, popularity: int}>
     */
    public function getChildMoves(int $positionId): array
    {
        $rows = $this->entityManager->createQuery(
            'SELECT m, IDENTITY(m.toBoardPosition) AS toPositionId, COUNT(gm.id) AS popularity
             FROM App\Entity\Move m
             LEFT JOIN App\Entity\GameMove gm WITH gm.move = m
             WHERE m.fromBoardPosition = :position
             GROUP BY m.id, toPositionId
             ORDER BY popularity DESC'
        )->setParameter('position', $positionId)->getResult();

        $result = [];

        foreach ($rows as $row) {
            /** @var Move $move */
            $move = $row[0];
            $toBoardPosition = $move->getToBoardPosition();
            $result[] = [
                'moveData' => base64_encode($move->getMoveData()->data),
                'toBoardPositionId' => (int) $row['toPositionId'],
                'toBoardPositionData' => base64_encode($toBoardPosition->getBoardPositionData()),
                'popularity' => (int) $row['popularity'],
            ];
        }

        return $result;
    }

    /**
     * Outcome distribution (relative to whoever is to move next) for games
     * whose move sequence reaches exactly this BoardPosition at exactly
     * this ply depth (1-indexed: the position after $ply moves).
     *
     * @return array{win: int, lose: int, draw: int, inProgress: int}
     */
    public function getLeafStats(int $positionId, int $ply): array
    {
        $sideToMoveIsWhite = 0 === $ply % 2;

        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT g.is_white, g.white_wins, g.draw, g.game_over_at
             FROM (
                 SELECT gm.game_id, gm.move_id, ROW_NUMBER() OVER (PARTITION BY gm.game_id ORDER BY gm.id) AS ply
                 FROM game_move gm
             ) ranked
             JOIN move mv ON mv.id = ranked.move_id
             JOIN game g ON g.id = ranked.game_id
             WHERE ranked.ply = :ply AND mv.to_board_position_id = :positionId',
            ['ply' => $ply, 'positionId' => $positionId]
        )->fetchAllAssociative();

        $stats = ['win' => 0, 'lose' => 0, 'draw' => 0, 'inProgress' => 0];

        foreach ($rows as $row) {
            if (null === $row['game_over_at']) {
                ++$stats['inProgress'];
                continue;
            }

            if ($row['draw']) {
                ++$stats['draw'];
                continue;
            }
            $ownerIsWhite = (bool) $row['is_white'];
            $whiteWins = (bool) $row['white_wins'];
            $ownerWon = $ownerIsWhite === $whiteWins;
            $sideToMoveWon = $ownerIsWhite === $sideToMoveIsWhite ? $ownerWon : !$ownerWon;

            if ($sideToMoveWon) {
                ++$stats['win'];
            } else {
                ++$stats['lose'];
            }
        }

        return $stats;
    }
}
