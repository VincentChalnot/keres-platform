<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\PieceColor;
use App\Model\TimeControl;
use App\Repository\UserRepository;
use App\Service\GameFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dev/test-only: creates a multiplayer game with a given time control via
 * GameFactory (so ClockManager::arm() runs correctly). There is no
 * matchmaking UI yet (Phase 3), so this is the only way to exercise the
 * Phase 2 clock/adjudication machinery end-to-end before then.
 */
#[AsCommand(name: 'app:create-test-game', description: 'Create a multiplayer game with a given time control for manual/QA testing')]
class CreateTestGameCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly GameFactory $gameFactory,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('white-email', InputArgument::REQUIRED)
            ->addArgument('black-email', InputArgument::REQUIRED)
            ->addOption('initial-seconds', null, InputOption::VALUE_REQUIRED, 'REALTIME initial seconds; omit for unlimited')
            ->addOption('increment-seconds', null, InputOption::VALUE_REQUIRED, 'REALTIME increment seconds', '0')
            ->addOption('rated', null, InputOption::VALUE_NONE)
            ->addOption('wait', null, InputOption::VALUE_NONE, 'Print the UUID and wait for Enter before starting the clock, so a human has time to open both browser tabs before the 30s first-move clamp (03-time-control.md sec 7.1) starts ticking');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $white = $this->userRepository->findByEmail($input->getArgument('white-email'));
        $black = $this->userRepository->findByEmail($input->getArgument('black-email'));

        if (!$white || !$black) {
            $output->writeln('<error>Both users must already exist.</error>');

            return Command::FAILURE;
        }

        $initialSeconds = $input->getOption('initial-seconds');
        $timeControl = null === $initialSeconds
            ? TimeControl::unlimited()
            : TimeControl::realtime((int) $initialSeconds, (int) $input->getOption('increment-seconds'));

        $wait = (bool) $input->getOption('wait');

        $game = $this->gameFactory->createMultiplayerGame(
            $white,
            $black,
            PieceColor::WHITE,
            $timeControl,
            (bool) $input->getOption('rated'),
            !$wait,
        );

        $this->entityManager->persist($game);
        $this->entityManager->flush();

        if ($wait) {
            $output->writeln($game->getUuid()->toRfc4122());
            $output->writeln('Clock is not armed yet. Open both browser tabs on /play/{uuid} now, then press Enter to start the 30s first-move clamp.');
            fgets(\STDIN);
            $this->gameFactory->arm($game);
            $this->entityManager->flush();
            $output->writeln('Clock armed.');
        } else {
            $output->writeln($game->getUuid()->toRfc4122());
        }

        return Command::SUCCESS;
    }
}
