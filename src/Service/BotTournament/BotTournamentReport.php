<?php

declare(strict_types=1);

namespace App\Service\BotTournament;

use App\Model\SpeedCategory;
use App\Repository\UserRepository;
use App\Service\Rating\RatingUpdater;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Read model of everything ever played between the bot accounts: per-level
 * record + current Glicko-2 ratings, head-to-head matrix, and how much of the
 * tournament is still queued / running.
 */
class BotTournamentReport
{
    public function __construct(
        private readonly BotAccounts $botAccounts,
        private readonly UserRepository $userRepository,
        private readonly RatingUpdater $ratingUpdater,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{
     *     levels: list<int>,
     *     categories: list<string>,
     *     standings: list<array{level: int, games: int, wins: int, draws: int, losses: int, score: ?float, ratings: array<string, array{rating: int, deviation: int, games: int}>}>,
     *     matrix: array<int, array<int, array{score: float, games: int}>>,
     *     ongoing: int,
     *     queued: ?int
     * }
     */
    public function build(): array
    {
        $botIds = $this->botAccounts->allIds();
        $levelOf = array_flip($botIds);
        $levels = array_keys($botIds);

        $h2h = [];
        $record = array_fill_keys($levels, ['w' => 0, 'd' => 0, 'l' => 0]);
        $ongoing = 0;

        if ([] !== $botIds) {
            foreach ($this->finishedGames($botIds) as $row) {
                $white = $levelOf[(string) $row['white']] ?? null;
                $black = $levelOf[(string) $row['black']] ?? null;

                if (null === $white || null === $black) {
                    continue;
                }

                $n = (int) $row['n'];
                $whitePoints = $row['draw'] ? 0.5 : ($row['white_wins'] ? 1.0 : 0.0);

                foreach ([[$white, $black, $whitePoints], [$black, $white, 1.0 - $whitePoints]] as [$me, $opp, $points]) {
                    $h2h[$me][$opp]['score'] = ($h2h[$me][$opp]['score'] ?? 0.0) + $points * $n;
                    $h2h[$me][$opp]['games'] = ($h2h[$me][$opp]['games'] ?? 0) + $n;
                    $record[$me][1.0 === $points ? 'w' : (0.5 === $points ? 'd' : 'l')] += $n;
                }
            }

            $ongoing = $this->ongoingGames($botIds);
        }

        $standings = [];
        $activeCategories = [];

        foreach (array_reverse($levels) as $level) {
            $r = $record[$level];
            $games = $r['w'] + $r['d'] + $r['l'];
            $user = $this->userRepository->find($botIds[$level]);
            $ratings = [];

            if (null !== $user) {
                foreach ($this->ratingUpdater->currentRatingsForAllCategories($user) as $category => $rating) {
                    if ($rating->gamesPlayed > 0) {
                        $ratings[$category] = ['rating' => $rating->display(), 'deviation' => (int) round($rating->deviation), 'games' => $rating->gamesPlayed];
                        $activeCategories[$category] = true;
                    }
                }
            }

            $standings[] = [
                'level' => $level,
                'games' => $games,
                'wins' => $r['w'],
                'draws' => $r['d'],
                'losses' => $r['l'],
                'score' => $games > 0 ? 100 * ($r['w'] + $r['d'] / 2) / $games : null,
                'ratings' => $ratings,
            ];
        }

        $categories = array_values(array_filter(
            array_map(static fn (SpeedCategory $c): string => strtolower($c->name), SpeedCategory::cases()),
            static fn (string $c): bool => isset($activeCategories[$c]),
        ));

        return [
            'levels' => $levels,
            'categories' => $categories,
            'standings' => $standings,
            'matrix' => $h2h,
            'ongoing' => $ongoing,
            'queued' => $this->queuedGames(),
        ];
    }

    /**
     * Games enqueued by `app:bot-tournament` and not yet fully handled
     * (includes the ones being played right now). Null when the transport is
     * not the Doctrine one and cannot be inspected.
     */
    public function queuedGames(): ?int
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue AND body LIKE :type',
                ['queue' => 'default', 'type' => '%PlayBotGameMessage%'],
            );
        } catch (DbalException) {
            return null;
        }
    }

    /**
     * Finished, non-aborted games between bots.
     *
     * @param array<int, string> $botIds
     *
     * @return list<array<string, mixed>>
     */
    public function finishedGames(array $botIds): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT wp.user_id AS white, bp.user_id AS black, g.white_wins, g."draw" AS draw, COUNT(*) AS n
               FROM game g
               JOIN game_player wp ON wp.game_id = g.id AND wp.color_value = 0
               JOIN game_player bp ON bp.game_id = g.id AND bp.color_value = 1
              WHERE g.game_over_at IS NOT NULL
                AND g.end_reason_value <> 6
                AND g.deleted_at IS NULL
                AND wp.user_id IN (:ids) AND bp.user_id IN (:ids)
              GROUP BY wp.user_id, bp.user_id, g.white_wins, g."draw"',
            ['ids' => array_values($botIds)],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    /** @param array<int, string> $botIds */
    private function ongoingGames(array $botIds): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
               FROM game g
               JOIN game_player wp ON wp.game_id = g.id AND wp.color_value = 0
               JOIN game_player bp ON bp.game_id = g.id AND bp.color_value = 1
              WHERE g.game_over_at IS NULL
                AND g.deleted_at IS NULL
                AND wp.user_id IN (:ids) AND bp.user_id IN (:ids)',
            ['ids' => array_values($botIds)],
            ['ids' => ArrayParameterType::STRING],
        );
    }
}
