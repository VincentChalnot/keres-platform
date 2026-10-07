<?php

declare(strict_types=1);

namespace App\Command;

use App\Engine\EngineApi;
use App\Engine\GameEngine;
use App\Entity\Game;
use App\Entity\User;
use App\Exception\MoveFlaggedException;
use App\Model\Notification\NotificationPreferences;
use App\Model\Notification\NotificationType;
use App\Model\PieceColor;
use App\Model\TimeControl;
use App\Repository\UserRepository;
use App\Service\Game\ClockManager;
use App\Service\Game\GameLifecycleManager;
use App\Service\GameFactory;
use App\Service\Rating\RatingUpdater;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Round-robin tournament between the engine's AI levels, each level playing
 * under a dedicated bot account (`bot-level-N@playkeres.com`). Games are
 * regular rated MULTIPLAYER games, so they flow through the same
 * GameEngine/RatingUpdater path as human games and feed the shared board
 * tree + the Glicko-2 pools.
 *
 * Resumable: games already finished between two bots count towards the
 * target, so re-running only plays what is missing.
 */
#[AsCommand(name: 'app:bot-tournament', description: 'Play a round-robin tournament between bot accounts (one per AI level) to seed games and ratings')]
class BotTournamentCommand extends Command
{
    private const string EMAIL_PATTERN = 'bot-level-%d@playkeres.com';
    private const string USERNAME_PATTERN = 'keres-bot-%d';
    private const int MAX_CONSECUTIVE_FAILURES = 3;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly GameFactory $gameFactory,
        private readonly GameEngine $gameEngine,
        private readonly EngineApi $engineApi,
        private readonly ClockManager $clockManager,
        private readonly GameLifecycleManager $gameLifecycleManager,
        private readonly RatingUpdater $ratingUpdater,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('games-per-pair', InputArgument::OPTIONAL, 'Games per pair of levels (both colours combined; split evenly between the two colour assignments)', '10')
            ->addOption('min-level', null, InputOption::VALUE_REQUIRED, 'Lowest AI level to include', '5')
            ->addOption('max-level', null, InputOption::VALUE_REQUIRED, 'Highest AI level to include', '10')
            ->addOption('initial-seconds', null, InputOption::VALUE_REQUIRED, 'Real-time clock, initial seconds per side (the clock is what makes the games rated)', '900')
            ->addOption('increment-seconds', null, InputOption::VALUE_REQUIRED, 'Real-time clock, increment per move', '5')
            ->addOption('max-plies', null, InputOption::VALUE_REQUIRED, 'Abort (unrated, not counted) a game that goes past this many plies', '800')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show how many games would be played, without creating accounts or playing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $gamesPerPair = (int) $input->getArgument('games-per-pair');
        $minLevel = (int) $input->getOption('min-level');
        $maxLevel = (int) $input->getOption('max-level');
        $maxPlies = (int) $input->getOption('max-plies');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($gamesPerPair < 1 || $minLevel < 1 || $maxLevel > 10 || $minLevel >= $maxLevel || $maxPlies < 1) {
            $io->error('Need games-per-pair >= 1, 1 <= min-level < max-level <= 10 and max-plies >= 1.');

            return Command::INVALID;
        }

        try {
            $timeControl = TimeControl::realtime((int) $input->getOption('initial-seconds'), (int) $input->getOption('increment-seconds'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $levels = range($minLevel, $maxLevel);
        $botIds = $dryRun ? $this->existingBotIds($levels) : $this->ensureBots($levels);

        $tasks = $this->plan($levels, $gamesPerPair, $botIds);
        $totalPairs = \count($levels) * (\count($levels) - 1) / 2;

        $io->title('Keres bot tournament');
        $io->text(\sprintf(
            'Levels %d-%d (%d pairs), %d games per pair, clock %s+%ds (%s pool): %d games to play.',
            $minLevel,
            $maxLevel,
            $totalPairs,
            $gamesPerPair,
            $input->getOption('initial-seconds'),
            $input->getOption('increment-seconds'),
            $timeControl->speedCategory()?->name ?? 'n/a',
            \count($tasks),
        ));

        if ($dryRun || [] === $tasks) {
            if ([] === $tasks) {
                $io->success('Nothing to play: every pair already has enough games.');
                $this->renderSummary($io, $levels, $botIds, $timeControl);
            }

            return Command::SUCCESS;
        }

        $this->abortDanglingGames($botIds);

        // Random order so Glicko's sequential updates are not biased by
        // "all the L5-vs-L6 games first".
        shuffle($tasks);

        $gameUuid = null;
        $played = 0;
        $failures = 0;
        $consecutiveFailures = 0;
        $startedAt = microtime(true);

        foreach ($tasks as $i => [$whiteLevel, $blackLevel]) {
            $gameStart = microtime(true);
            $prefix = \sprintf('[%d/%d] L%d (white) vs L%d (black)', $i + 1, \count($tasks), $whiteLevel, $blackLevel);

            try {
                $game = $this->playGame($botIds[$whiteLevel], $botIds[$blackLevel], $whiteLevel, $blackLevel, $timeControl, $maxPlies, $gameUuid);
                $consecutiveFailures = 0;
                ++$played;
                $output->writeln(\sprintf(
                    '%s: %s by %s in %d plies (%.1fs)',
                    $prefix,
                    $game->isDraw() ? 'draw' : ($game->isWhiteWins() ? '1-0' : '0-1'),
                    strtolower($game->getEndReason()->name),
                    $game->getGameMoves()->count(),
                    microtime(true) - $gameStart,
                ));
            } catch (\Throwable $e) {
                ++$failures;
                ++$consecutiveFailures;
                $output->writeln(\sprintf('<error>%s: failed (%s)</error>', $prefix, $e->getMessage()));
                $this->recoverAfterFailure($gameUuid);

                if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                    $io->error(\sprintf('%d consecutive failures, is the engine reachable? Stopping; re-run to resume.', $consecutiveFailures));

                    return Command::FAILURE;
                }
            } finally {
                $this->entityManager->clear();
                $gameUuid = null;
            }
        }

        $io->success(\sprintf('%d games played, %d failed, in %.0fs.', $played, $failures, microtime(true) - $startedAt));
        $this->renderSummary($io, $levels, $botIds, $timeControl);

        return $failures > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param array<int, string> $botIds level => user uuid
     * @param-out string|null    $gameUuid set as soon as the game row exists, so a failure can clean it up
     */
    private function playGame(string $whiteId, string $blackId, int $whiteLevel, int $blackLevel, TimeControl $timeControl, int $maxPlies, ?string &$gameUuid): Game
    {
        $white = $this->entityManager->find(User::class, $whiteId) ?? throw new \RuntimeException('White bot account vanished.');
        $black = $this->entityManager->find(User::class, $blackId) ?? throw new \RuntimeException('Black bot account vanished.');

        $game = $this->gameFactory->createMultiplayerGame($white, $black, PieceColor::WHITE, $timeControl, true);
        $this->entityManager->persist($game);
        $this->entityManager->flush();
        $gameUuid = $game->getUuid()->toRfc4122();

        while (!$game->isGameOver()) {
            if ($game->getGameMoves()->count() >= $maxPlies) {
                $this->abort($game);

                throw new \RuntimeException(\sprintf('exceeded %d plies, aborted', $maxPlies));
            }

            $level = $game->isWhiteTurn() ? $whiteLevel : $blackLevel;
            $move = $this->engineApi->aiMove($game->getMovesData(), $level);

            try {
                // Stamped after the engine answered: a bot's thinking time is
                // charged like anyone else's (the increment keeps it sane).
                $this->gameEngine->applyMove($game, $move, $this->clockManager->nowMicros());
            } catch (MoveFlaggedException) {
                break; // finalised as a timeout (a real, rated result)
            }
        }

        return $game;
    }

    /**
     * @param list<int> $levels
     *
     * @return array<int, string> level => user uuid
     */
    private function existingBotIds(array $levels): array
    {
        $ids = [];

        foreach ($levels as $level) {
            $user = $this->userRepository->findByEmail(\sprintf(self::EMAIL_PATTERN, $level));
            $ids[$level] = $user?->getId()->toRfc4122() ?? '00000000-0000-0000-0000-'.str_pad((string) $level, 12, '0', \STR_PAD_LEFT);
        }

        return $ids;
    }

    /**
     * @param list<int> $levels
     *
     * @return array<int, string> level => user uuid
     */
    private function ensureBots(array $levels): array
    {
        $ids = [];

        foreach ($levels as $level) {
            $email = \sprintf(self::EMAIL_PATTERN, $level);
            $user = $this->userRepository->findByEmail($email);

            if (null === $user) {
                $user = new User($email);
                $user->setUsername(\sprintf(self::USERNAME_PATTERN, $level));
                $user->setDisplayName(\sprintf('Keres Bot (level %d)', $level));
                $this->entityManager->persist($user);
            }

            // Fake mailbox: never email it, and keep the bell empty.
            $enabled = array_fill_keys(array_map(static fn (NotificationType $t): string => $t->value, NotificationType::cases()), false);
            NotificationPreferences::fromUser($user)->withEmail($enabled)->withInApp($enabled)->applyTo($user);

            $this->entityManager->flush();
            $ids[$level] = $user->getId()->toRfc4122();
        }

        $this->entityManager->clear();

        return $ids;
    }

    /**
     * Games still to play as [whiteLevel, blackLevel] tuples. Each pair's
     * target is split between the two colour assignments (the odd game, if
     * any, alternates from pair to pair), minus what is already finished.
     *
     * @param list<int>          $levels
     * @param array<int, string> $botIds
     *
     * @return list<array{int, int}>
     */
    private function plan(array $levels, int $gamesPerPair, array $botIds): array
    {
        $done = $this->finishedCounts($botIds);
        $tasks = [];
        $pairIndex = 0;

        foreach ($levels as $a) {
            foreach ($levels as $b) {
                if ($b <= $a) {
                    continue;
                }

                $aWhiteTarget = intdiv($gamesPerPair, 2) + (0 === $pairIndex % 2 ? $gamesPerPair % 2 : 0);
                $bWhiteTarget = $gamesPerPair - $aWhiteTarget;
                ++$pairIndex;

                $aWhite = max(0, $aWhiteTarget - ($done[$botIds[$a]][$botIds[$b]] ?? 0));
                $bWhite = max(0, $bWhiteTarget - ($done[$botIds[$b]][$botIds[$a]] ?? 0));

                for ($n = 0; $n < $aWhite; ++$n) {
                    $tasks[] = [$a, $b];
                }

                for ($n = 0; $n < $bWhite; ++$n) {
                    $tasks[] = [$b, $a];
                }
            }
        }

        return $tasks;
    }

    /**
     * Finished, non-aborted games between bots, keyed [whiteUserId][blackUserId].
     *
     * @param array<int, string> $botIds
     *
     * @return array<string, array<string, int>>
     */
    private function finishedCounts(array $botIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT wp.user_id AS white, bp.user_id AS black, COUNT(*) AS n
               FROM game g
               JOIN game_player wp ON wp.game_id = g.id AND wp.color_value = 0
               JOIN game_player bp ON bp.game_id = g.id AND bp.color_value = 1
              WHERE g.game_over_at IS NOT NULL
                AND g.end_reason_value <> 6
                AND g.deleted_at IS NULL
                AND wp.user_id IN (:ids) AND bp.user_id IN (:ids)
              GROUP BY wp.user_id, bp.user_id',
            ['ids' => array_values($botIds)],
            ['ids' => ArrayParameterType::STRING],
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['white']][(string) $row['black']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Games left unfinished by an interrupted earlier run (Ctrl-C, crash)
     * would otherwise stay "ongoing" forever.
     *
     * @param array<int, string> $botIds
     */
    private function abortDanglingGames(array $botIds): void
    {
        $uuids = $this->connection->fetchFirstColumn(
            'SELECT g.uuid
               FROM game g
               JOIN game_player wp ON wp.game_id = g.id AND wp.color_value = 0
               JOIN game_player bp ON bp.game_id = g.id AND bp.color_value = 1
              WHERE g.game_over_at IS NULL
                AND wp.user_id IN (:ids) AND bp.user_id IN (:ids)',
            ['ids' => array_values($botIds)],
            ['ids' => ArrayParameterType::STRING],
        );

        foreach ($uuids as $uuid) {
            $this->recoverAfterFailure((string) $uuid);
        }

        $this->entityManager->clear();
    }

    /** Best effort: never let cleanup mask the original error. */
    private function recoverAfterFailure(?string $gameUuid): void
    {
        if (null === $gameUuid) {
            return;
        }

        try {
            if (!$this->entityManager->isOpen()) {
                return;
            }

            $this->entityManager->clear();
            $game = $this->entityManager->getRepository(Game::class)->findOneBy(['uuid' => $gameUuid]);

            if ($game instanceof Game && !$game->isGameOver()) {
                $this->abort($game);
            }
        } catch (\Throwable) {
        }
    }

    private function abort(Game $game): void
    {
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($game): void {
            $em->find(Game::class, $game->getId(), LockMode::PESSIMISTIC_WRITE);

            if ($game->isGameOver()) {
                return;
            }

            $this->clockManager->stop($game, $this->clockManager->nowMicros());
            $this->gameLifecycleManager->finaliseAbort($game);
            $em->flush();
        });
    }

    /**
     * Standings from the database (everything ever played between these
     * bots, not just this run): per-level record + current Glicko-2 rating,
     * then the head-to-head score matrix.
     *
     * @param list<int>          $levels
     * @param array<int, string> $botIds
     */
    private function renderSummary(SymfonyStyle $io, array $levels, array $botIds, TimeControl $timeControl): void
    {
        $levelOf = array_flip($botIds);
        $rows = $this->connection->fetchAllAssociative(
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

        /** @var array<int, array<int, array{points: float, games: int}>> $h2h row level => opponent level => result */
        $h2h = [];
        /** @var array<int, array{w: int, d: int, l: int}> $record */
        $record = array_fill_keys($levels, ['w' => 0, 'd' => 0, 'l' => 0]);

        foreach ($rows as $row) {
            $white = $levelOf[(string) $row['white']] ?? null;
            $black = $levelOf[(string) $row['black']] ?? null;

            if (null === $white || null === $black) {
                continue;
            }

            $n = (int) $row['n'];
            $whitePoints = $row['draw'] ? 0.5 : ($row['white_wins'] ? 1.0 : 0.0);

            foreach ([[$white, $black, $whitePoints], [$black, $white, 1.0 - $whitePoints]] as [$me, $opp, $points]) {
                $h2h[$me][$opp]['points'] = ($h2h[$me][$opp]['points'] ?? 0.0) + $points * $n;
                $h2h[$me][$opp]['games'] = ($h2h[$me][$opp]['games'] ?? 0) + $n;
                $record[$me][1.0 === $points ? 'w' : (0.5 === $points ? 'd' : 'l')] += $n;
            }
        }

        $category = $timeControl->speedCategory();
        $standings = new Table($io);
        $standings->setHeaderTitle('Standings');
        $standings->setHeaders(['Level', 'Games', 'W', 'D', 'L', 'Score', 'Glicko-2 ('.($category?->name ?? 'n/a').')']);

        foreach (array_reverse($levels) as $level) {
            $r = $record[$level];
            $games = $r['w'] + $r['d'] + $r['l'];
            $rating = null !== $category && isset($botIds[$level]) && null !== ($user = $this->userRepository->find($botIds[$level]))
                ? $this->ratingUpdater->currentRating($user, $category)
                : null;

            $standings->addRow([
                $level,
                $games,
                $r['w'],
                $r['d'],
                $r['l'],
                $games > 0 ? \sprintf('%.1f%%', 100 * ($r['w'] + $r['d'] / 2) / $games) : '-',
                null === $rating ? '-' : \sprintf('%d ± %d', $rating->display(), (int) round($rating->deviation)),
            ]);
        }

        $standings->render();

        $matrix = new Table($io);
        $matrix->setHeaderTitle('Head-to-head: row\'s score % against column');
        $matrix->setHeaders(array_merge(['vs'], array_map(static fn (int $l): string => 'L'.$l, $levels)));

        foreach ($levels as $me) {
            $line = ['L'.$me];

            foreach ($levels as $opp) {
                $cell = $h2h[$me][$opp] ?? null;
                $line[] = $me === $opp ? '·' : (null === $cell || 0 === $cell['games'] ? '-' : \sprintf('%.0f%% (%d)', 100 * $cell['points'] / $cell['games'], $cell['games']));
            }

            $matrix->addRow($line);
        }

        $matrix->render();
    }
}
