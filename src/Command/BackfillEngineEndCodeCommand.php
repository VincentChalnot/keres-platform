<?php

declare(strict_types=1);

namespace App\Command;

use App\Engine\EngineApi;
use App\Entity\Game;
use App\Model\GameEndReason;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfill: asks the engine why each already-finished engine-ended game
 * ended and stores the code on the game (live games get it from
 * `GameEngine::applyMove()`). Until a game has its code, the client shows a
 * neutral "White wins." instead of a reason.
 *
 * The engine's rules change over time, so a game is only given a code when
 * the engine, replaying its moves today, still ends it with the very result
 * recorded for it; a game that ended under an earlier rule (or whose history
 * the engine no longer accepts) keeps NULL rather than get a reason that is
 * not the real one. Idempotent and resumable: games that already have a code
 * are not selected (those left NULL are re-examined on every run).
 */
#[AsCommand(name: 'app:games:backfill-engine-end-code', description: 'Store the engine\'s game-over reason on every finished engine-ended game that has none')]
class BackfillEngineEndCodeCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EngineApi $engineApi,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Stop after this many games (default: all)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report how many games are left');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $gameIds = $this->entityManager->getConnection()->executeQuery(
            'SELECT id FROM game WHERE end_reason_value = :engine AND engine_end_code IS NULL AND game_over_at IS NOT NULL ORDER BY id ASC',
            ['engine' => GameEndReason::ENGINE->value],
        )->fetchFirstColumn();

        $io->writeln(\sprintf('%d finished engine-ended game(s) without an end code.', \count($gameIds)));

        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        if (null !== $input->getOption('limit')) {
            $gameIds = \array_slice($gameIds, 0, max(1, (int) $input->getOption('limit')));
        }

        $failed = 0;
        $skipped = 0;

        foreach ($io->progressIterate($gameIds) as $gameId) {
            try {
                $game = $this->entityManager->find(Game::class, $gameId);

                if ($game instanceof Game) {
                    $board = $this->engineApi->replayMoves($game->getMovesData());

                    if (!$board->gameOver || $board->draw !== $game->isDraw() || $board->whiteWins !== $game->isWhiteWins()) {
                        ++$skipped;
                    } else {
                        $game->setEngineEndCode($this->engineApi->gameOverReason($game->getMovesData()));
                        $this->entityManager->flush();
                    }
                }
            } catch (\Throwable $e) {
                ++$failed;
                $io->warning(\sprintf('Game %d: %s', $gameId, $e->getMessage()));
            }

            $this->entityManager->clear();
        }

        $io->success(\sprintf('%d game(s) processed, %d left without a code (ended under other rules), %d failed.', \count($gameIds), $skipped, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
