<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\PlayBotGameMessage;
use App\Model\TimeControl;
use App\Service\BotTournament\BotAccounts;
use App\Service\BotTournament\BotGamePlayer;
use App\Service\BotTournament\BotTournamentReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Round-robin tournament between the engine's AI levels, each level playing
 * under a dedicated bot account (`bot-level-N@playkeres.com`). The command only
 * plans the games and enqueues one PlayBotGameMessage per game; the async
 * workers play them (see PlayBotGameHandler) and the results show up in the
 * admin "Bot tournament" panel.
 *
 * Resumable: games already finished between two bots count towards the
 * target, so re-running only enqueues what is missing (and refuses while a
 * previous run is still queued, unless --force).
 */
#[AsCommand(name: 'app:bot-tournament', description: 'Queue a round-robin tournament between bot accounts (one per AI level) to seed games and ratings')]
class BotTournamentCommand extends Command
{
    public function __construct(
        private readonly BotAccounts $botAccounts,
        private readonly BotTournamentReport $report,
        private readonly BotGamePlayer $botGamePlayer,
        private readonly MessageBusInterface $messageBus,
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
            ->addOption('force', null, InputOption::VALUE_NONE, 'Enqueue even if games from a previous run are still queued')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show how many games would be queued, without creating accounts or queueing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $gamesPerPair = (int) $input->getArgument('games-per-pair');
        $minLevel = (int) $input->getOption('min-level');
        $maxLevel = (int) $input->getOption('max-level');
        $maxPlies = (int) $input->getOption('max-plies');
        $initialSeconds = (int) $input->getOption('initial-seconds');
        $incrementSeconds = (int) $input->getOption('increment-seconds');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($gamesPerPair < 1 || $minLevel < 1 || $maxLevel > 10 || $minLevel >= $maxLevel || $maxPlies < 1) {
            $io->error('Need games-per-pair >= 1, 1 <= min-level < max-level <= 10 and max-plies >= 1.');

            return Command::INVALID;
        }

        try {
            $timeControl = TimeControl::realtime($initialSeconds, $incrementSeconds);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $levels = range($minLevel, $maxLevel);
        $botIds = $dryRun ? $this->botAccounts->existingIds($levels) : $this->botAccounts->ensure($levels);

        $tasks = $this->plan($levels, $gamesPerPair, $botIds);
        $totalPairs = \count($levels) * (\count($levels) - 1) / 2;

        $io->title('Keres bot tournament');
        $io->text(\sprintf(
            'Levels %d-%d (%d pairs), %d games per pair, clock %d+%ds (%s pool): %d games to play.',
            $minLevel,
            $maxLevel,
            $totalPairs,
            $gamesPerPair,
            $initialSeconds,
            $incrementSeconds,
            $timeControl->speedCategory()?->name ?? 'n/a',
            \count($tasks),
        ));

        if ([] === $tasks) {
            $io->success('Nothing to play: every pair already has enough games.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            return Command::SUCCESS;
        }

        $queued = $this->report->queuedGames();

        if (!$input->getOption('force') && null !== $queued && $queued > 0) {
            $io->error(\sprintf('%d tournament games are still queued or being played; enqueueing again now would duplicate them. Wait for them to finish, or use --force.', $queued));

            return Command::FAILURE;
        }

        // Nothing queued or in flight, so an unfinished bot game belongs to a
        // process that died (memory fatal, kill): abort it rather than let its
        // clock flag it into a rated result nobody played.
        if (0 === $queued) {
            $aborted = $this->botGamePlayer->abortDanglingGames();

            if ($aborted > 0) {
                $io->note(\sprintf('Aborted %d dangling bot game(s) left by an interrupted run.', $aborted));
            }
        }

        // Random order so Glicko's sequential updates are not biased by
        // "all the L5-vs-L6 games first".
        shuffle($tasks);

        foreach ($tasks as [$whiteLevel, $blackLevel]) {
            $this->messageBus->dispatch(new PlayBotGameMessage($whiteLevel, $blackLevel, $initialSeconds, $incrementSeconds, $maxPlies));
        }

        $io->success(\sprintf('%d games queued; the async workers play them in the background. Follow the results in the admin "Bot tournament" panel.', \count($tasks)));

        return Command::SUCCESS;
    }

    /**
     * Games still to play as [whiteLevel, blackLevel] tuples. Each pair's
     * target is split between the two colour assignments (the odd game, if
     * any, alternates from pair to pair), minus what is already finished.
     *
     * @param list<int> $levels
     * @param array<int, string> $botIds
     *
     * @return list<array{int, int}>
     */
    private function plan(array $levels, int $gamesPerPair, array $botIds): array
    {
        $done = [];

        foreach ($this->report->finishedGames($botIds) as $row) {
            $done[(string) $row['white']][(string) $row['black']] = ($done[(string) $row['white']][(string) $row['black']] ?? 0) + (int) $row['n'];
        }

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
}
