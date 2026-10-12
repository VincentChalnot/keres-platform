<?php

declare(strict_types=1);

namespace App\Service\BotTournament;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Wipes what the bots played against each other (`app:bot-tournament --reset`): the games between two bot accounts are
 * soft-deleted and the bots' ratings are dropped, so the tournament - and the ladder of ratings that human players
 * meet - restarts from scratch. Games bots played against humans are never touched; the bots' ratings are the one
 * shared pool, so they restart at the default rating and move with every later game (see `docs/features.md`).
 */
class BotTournamentReset
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * What `reset()` would remove.
     *
     * @param array<int, string> $botIds
     *
     * @return array{games: int, ratings: int}
     */
    public function counts(array $botIds): array
    {
        if ([] === $botIds) {
            return ['games' => 0, 'ratings' => 0];
        }

        $params = ['ids' => array_values($botIds)];
        $types = ['ids' => ArrayParameterType::STRING];

        return [
            'games' => (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM game g
                   JOIN game_player wp ON wp.game_id = g.id AND wp.color_value = 0
                   JOIN game_player bp ON bp.game_id = g.id AND bp.color_value = 1
                  WHERE g.deleted_at IS NULL AND wp.user_id IN (:ids) AND bp.user_id IN (:ids)',
                $params,
                $types,
            ),
            'ratings' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_rating WHERE user_id IN (:ids)', $params, $types),
        ];
    }

    /**
     * @param array<int, string> $botIds
     *
     * @return array{games: int, ratings: int} what was removed
     */
    public function reset(array $botIds): array
    {
        if ([] === $botIds) {
            return ['games' => 0, 'ratings' => 0];
        }

        $params = ['ids' => array_values($botIds)];
        $types = ['ids' => ArrayParameterType::STRING];

        return $this->connection->transactional(function () use ($params, $types): array {
            $games = (int) $this->connection->executeStatement(
                'UPDATE game SET deleted_at = now()
                  WHERE deleted_at IS NULL AND id IN (
                    SELECT g.id FROM game g
                      JOIN game_player wp ON wp.game_id = g.id AND wp.color_value = 0
                      JOIN game_player bp ON bp.game_id = g.id AND bp.color_value = 1
                     WHERE wp.user_id IN (:ids) AND bp.user_id IN (:ids)
                  )',
                $params,
                $types,
            );
            $ratings = (int) $this->connection->executeStatement('DELETE FROM user_rating WHERE user_id IN (:ids)', $params, $types);

            return ['games' => $games, 'ratings' => $ratings];
        });
    }
}
