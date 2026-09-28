<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\EnvironmentCheck\EnvironmentCheckerInterface;
use App\Service\EnvironmentCheck\EnvironmentCheckFailedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every tagged `app.environment_checker` (see config/services.yaml)
 * and fails loudly if any of them reports a fatal misconfiguration. Invoked
 * by frankenphp/docker-entrypoint.sh before the app server starts, so a
 * misconfigured prod container never comes up serving traffic it can't
 * actually support (see MailerDsnEnvironmentChecker for the first check).
 */
#[AsCommand(name: 'app:check-environment', description: 'Fail fast if the running environment is missing required configuration')]
final class CheckEnvironmentCommand extends Command
{
    /** @param iterable<EnvironmentCheckerInterface> $checkers */
    public function __construct(
        #[AutowireIterator('app.environment_checker')]
        private readonly iterable $checkers,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $failures = [];

        foreach ($this->checkers as $checker) {
            try {
                $checker->check();
                $io->writeln(\sprintf('<info>✓</info> %s', $checker->getName()));
            } catch (EnvironmentCheckFailedException $exception) {
                $io->writeln(\sprintf('<error>✗</error> %s: %s', $checker->getName(), $exception->getMessage()));
                $failures[] = $checker->getName();
            }
        }

        if ([] !== $failures) {
            $io->error(\sprintf('Environment check failed: %s', implode(', ', $failures)));

            return Command::FAILURE;
        }

        $io->success('Environment checks passed.');

        return Command::SUCCESS;
    }
}
