<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI-only admin-role toggle (adapted from SidusUserBundle\Command\
 * PromoteUserCommand + UserManagementCommandHelper, collapsed into one
 * command since there's only a single command needing this UX here — no
 * ChangeUserPasswordCommand/CreateUserCommand siblings to share a helper
 * with). Mutates the existing User.roles JSON array directly; this is the
 * only interface for admin promotion, by design (no admin UI, no config).
 */
#[AsCommand(name: 'app:user:promote', description: 'Promote a user to ROLE_ADMIN, or demote them with --demote')]
class PromoteUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'The email of the user')
            ->addOption('demote', 'd', InputOption::VALUE_NONE, 'Remove the admin role instead of granting it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');

        if (null === $email) {
            if (!$input->isInteractive()) {
                $io->error('Missing "email" argument.');

                return Command::FAILURE;
            }
            $email = $this->getHelper('question')->ask($input, $output, new Question('<info>Email: </info>'));
        }

        $user = $this->userRepository->findByEmail((string) $email);

        if (null === $user) {
            $io->error(\sprintf('User "%s" does not exist.', $email));

            return Command::FAILURE;
        }

        $demote = (bool) $input->getOption('demote');
        $roles = $user->getRoles();

        if ($demote) {
            $roles = array_values(array_filter($roles, static fn (string $role): bool => 'ROLE_ADMIN' !== $role));
        } elseif (!\in_array('ROLE_ADMIN', $roles, true)) {
            $roles[] = 'ROLE_ADMIN';
        }

        $user->setRoles($roles);
        $this->entityManager->flush();

        $io->success(\sprintf('User %s was %s.', $email, $demote ? 'demoted from admin' : 'promoted to admin'));

        return Command::SUCCESS;
    }
}
