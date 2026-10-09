<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Game;
use App\Service\Evaluation\MoveEvaluator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfill: gives an engine evaluation to every existing move edge that has
 * none. Works game by game (a move's evaluation needs the history that led
 * to it), oldest first; edges already evaluated - by an earlier run, by the
 * live path, or by another game sharing the same line - are free, so the
 * command is idempotent and resumable (re-run it after an interruption).
 *
 * Edges that no game contains any more (their game was undone) have no
 * history to evaluate with and are left NULL; they are counted at the end.
 */
#[AsCommand(name: 'app:moves:evaluate', description: 'Compute the engine evaluation of every move that has none yet')]
class EvaluateMovesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MoveEvaluator $moveEvaluator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Stop after this many games (default: all)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report how many games / moves are left to evaluate');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connection = $this->entityManager->getConnection();

        $gameIds = $connection->executeQuery(
            'SELECT gm.game_id
             FROM game_move gm
             JOIN move m ON m.id = gm.move_id
             WHERE m.evaluation IS NULL
             GROUP BY gm.game_id
             ORDER BY gm.game_id ASC'
        )->fetchFirstColumn();

        $pendingMoves = (int) $connection->executeQuery('SELECT COUNT(*) FROM move WHERE evaluation IS NULL')->fetchOne();
        $io->writeln(\sprintf('%d move(s) without evaluation, reachable through %d game(s).', $pendingMoves, \count($gameIds)));

        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        $limit = null !== $input->getOption('limit') ? max(1, (int) $input->getOption('limit')) : null;

        if (null !== $limit) {
            $gameIds = \array_slice($gameIds, 0, $limit);
        }

        $failed = 0;

        foreach ($io->progressIterate($gameIds) as $gameId) {
            try {
                $game = $this->entityManager->find(Game::class, $gameId);

                if ($game instanceof Game) {
                    $this->moveEvaluator->evaluateAll($game);
                }
            } catch (\Throwable $e) {
                ++$failed;
                $io->warning(\sprintf('Game #%d skipped: %s', $gameId, $e->getMessage()));
            }

            // Long run: don't let the identity map hold every game ever loaded.
            $this->entityManager->clear();
        }

        $remaining = (int) $connection->executeQuery('SELECT COUNT(*) FROM move WHERE evaluation IS NULL')->fetchOne();
        $io->success(\sprintf('Done. %d move(s) still without evaluation (%d game(s) failed; edges not in any game are never evaluated).', $remaining, $failed));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
