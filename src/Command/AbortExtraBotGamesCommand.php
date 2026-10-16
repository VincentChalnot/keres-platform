<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Game;
use App\Repository\GameRepository;
use App\Service\Game\BotGameManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-off clean-up for "one bot game at a time" (D5/D6 of `docs/onboarding-plan.md`): a player who piled up several
 * running bot games (casual or rated) keeps the one played most recently (last move, else creation date) and the
 * others are aborted - no result, no rating, no ladder effect. Idempotent; run `--dry-run` first.
 */
#[AsCommand(name: 'app:bot-games:abort-extra', description: 'Abort every running bot game of a user except the most recently played one')]
class AbortExtraBotGamesCommand extends Command
{
    public function __construct(
        private readonly GameRepository $gameRepository,
        private readonly BotGameManager $botGameManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be aborted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var array<string, list<Game>> $byUser */
        $byUser = [];

        foreach ($this->gameRepository->findInProgressBotGames() as $game) {
            $byUser[$game->getCreatedBy()->getId()->toRfc4122()][] = $game;
        }

        $users = 0;
        $kept = 0;
        $aborted = 0;

        foreach ($byUser as $games) {
            if (\count($games) < 2) {
                continue;
            }

            ++$users;
            // Most recently played first; the id breaks ties deterministically.
            usort($games, static fn (Game $a, Game $b): int => [self::lastActivity($b), $b->getId()] <=> [self::lastActivity($a), $a->getId()]);
            $keep = array_shift($games);
            ++$kept;

            $io->writeln(\sprintf('%s: keep %s', $keep->getCreatedBy()->getUserIdentifier(), $keep->getUuid()->toRfc4122()));

            foreach ($games as $extra) {
                $io->writeln(\sprintf('  abort %s (%s)', $extra->getUuid()->toRfc4122(), $extra->isBotGame() ? 'rated' : 'casual'));

                if (!$dryRun) {
                    $this->botGameManager->abort($extra);
                }

                ++$aborted;
            }
        }

        $io->success(\sprintf(
            '%s%d user(s) with several bot games, %d game(s) kept, %d game(s) %s.',
            $dryRun ? '[dry run] ' : '',
            $users,
            $kept,
            $aborted,
            $dryRun ? 'would be aborted' : 'aborted',
        ));

        return Command::SUCCESS;
    }

    private static function lastActivity(Game $game): float
    {
        return (float) (($game->getLastMoveAt() ?? $game->getCreatedAt())?->format('U.u') ?? 0);
    }
}
