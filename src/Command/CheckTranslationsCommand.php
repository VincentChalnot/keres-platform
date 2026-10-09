<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Translation\TranslationCatalogueChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `bin/console app:translations:check` - CI check that the translation files of
 * every enabled locale agree (see TranslationCatalogueChecker): same files, same
 * keys, same ICU arguments. Complements Symfony's own `lint:translations` (ICU
 * validity per locale) and `debug:translation <locale> --only-missing` (keys
 * used in templates/PHP but absent from a catalogue).
 */
#[AsCommand(name: 'app:translations:check', description: 'Checks that all translation files have the same keys and ICU arguments in every enabled locale')]
final class CheckTranslationsCommand extends Command
{
    public function __construct(
        private readonly TranslationCatalogueChecker $checker,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $problems = $this->checker->check();

        if ([] === $problems) {
            $io->success('All translation catalogues are consistent.');

            return Command::SUCCESS;
        }

        $io->error(\sprintf('%d translation problem(s):', \count($problems)));
        $io->listing($problems);

        return Command::FAILURE;
    }
}
