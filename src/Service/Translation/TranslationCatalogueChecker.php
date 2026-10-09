<?php

declare(strict_types=1);

namespace App\Service\Translation;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps `translations/` honest, for `bin/console lint:translations` (CI) and
 * the PHPUnit suite. Convention: one `<domain>+intl-icu.<locale>.yaml` per
 * domain and enabled locale, nothing else. For every domain it checks:
 *
 *  - the file exists for every enabled locale;
 *  - the key sets are identical (no key only in `en`, none only in `fr`);
 *  - every value is a non-empty string that is valid ICU MessageFormat (and
 *    stays inside the subset the TypeScript formatter supports);
 *  - a key takes the same arguments, of the same kind, in every locale.
 */
final readonly class TranslationCatalogueChecker
{
    private const string FILE_PATTERN = '/^(?<domain>[a-z][a-z0-9_]*)\+intl-icu\.(?<locale>[a-z]{2,3})\.yaml$/';

    /** @param list<string> $enabledLocales */
    public function __construct(
        #[Autowire('%kernel.project_dir%/translations')]
        private string $translationsDir,
        #[Autowire('%kernel.enabled_locales%')]
        private array $enabledLocales,
    ) {
    }

    /** @return list<string> human-readable problems, empty when the catalogues are consistent */
    public function check(): array
    {
        $problems = [];
        /** @var array<string, array<string, array<string, string>>> $catalogues domain => locale => key => message */
        $catalogues = [];

        foreach ((new Finder())->files()->in($this->translationsDir)->depth(0)->name('*')->ignoreDotFiles(true) as $file) {
            $name = $file->getFilename();

            if (1 !== preg_match(self::FILE_PATTERN, $name, $match)) {
                $problems[] = \sprintf('%s: unexpected file name, expected <domain>+intl-icu.<locale>.yaml.', $name);

                continue;
            }

            if (!\in_array($match['locale'], $this->enabledLocales, true)) {
                $problems[] = \sprintf('%s: locale "%s" is not in framework.enabled_locales.', $name, $match['locale']);

                continue;
            }

            $catalogues[$match['domain']][$match['locale']] = $this->load($file->getPathname(), $name, $problems);
        }

        ksort($catalogues);

        foreach ($catalogues as $domain => $byLocale) {
            foreach ($this->enabledLocales as $locale) {
                if (!isset($byLocale[$locale])) {
                    $problems[] = \sprintf('%s+intl-icu.%s.yaml is missing.', $domain, $locale);
                }
            }

            $this->compare($domain, $byLocale, $problems);
        }

        return $problems;
    }

    /**
     * @param list<string> $problems
     *
     * @return array<string, string> flattened key => message
     */
    private function load(string $path, string $name, array &$problems): array
    {
        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            $problems[] = \sprintf('%s: %s', $name, $e->getMessage());

            return [];
        }

        if (!\is_array($data)) {
            return [];
        }

        $flat = [];
        $this->flatten($data, '', $flat, $name, $problems);

        return $flat;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param array<string, string> $flat
     * @param list<string> $problems
     */
    private function flatten(array $node, string $prefix, array &$flat, string $name, array &$problems): void
    {
        foreach ($node as $key => $value) {
            $path = $prefix.$key;

            if (\is_array($value)) {
                $this->flatten($value, $path.'.', $flat, $name, $problems);
            } elseif (!\is_string($value) || '' === trim($value)) {
                $problems[] = \sprintf('%s: "%s" must be a non-empty string (quote values YAML would read as another type).', $name, $path);
            } else {
                $flat[$path] = $value;
            }
        }
    }

    /**
     * @param array<string, array<string, string>> $byLocale
     * @param list<string> $problems
     */
    private function compare(string $domain, array $byLocale, array &$problems): void
    {
        $reference = $this->enabledLocales[0];
        $referenceKeys = array_keys($byLocale[$reference] ?? []);
        $isFrontend = 'frontend' === $domain || str_starts_with($domain, 'frontend_');

        foreach ($byLocale as $locale => $messages) {
            $file = \sprintf('%s+intl-icu.%s.yaml', $domain, $locale);

            foreach (array_diff($referenceKeys, array_keys($messages)) as $key) {
                $problems[] = \sprintf('%s: missing key "%s" (present in %s).', $file, $key, $reference);
            }

            foreach (array_diff(array_keys($messages), $referenceKeys) as $key) {
                $problems[] = \sprintf('%s: extra key "%s" (absent from %s).', $file, $key, $reference);
            }

            foreach ($messages as $key => $message) {
                try {
                    $arguments = IcuArguments::of($message);
                } catch (\InvalidArgumentException $e) {
                    $problems[] = \sprintf('%s: "%s" is not valid ICU: %s', $file, $key, $e->getMessage());

                    continue;
                }

                if (null === \MessageFormatter::create($locale, $message)) {
                    $problems[] = \sprintf('%s: "%s" is rejected by ICU: %s', $file, $key, intl_get_error_message());
                }

                $expected = isset($byLocale[$reference][$key]) ? $this->argumentsOrNull($byLocale[$reference][$key]) : null;

                if (null !== $expected && $expected !== $arguments) {
                    $problems[] = \sprintf(
                        '%s: "%s" takes arguments [%s], %s has [%s].',
                        $file,
                        $key,
                        $this->describe($arguments),
                        $reference,
                        $this->describe($expected),
                    );
                }

                if ($isFrontend && 1 === preg_match('/<[a-z][^>]*>/i', $message)) {
                    $problems[] = \sprintf('%s: "%s" contains HTML; front-end strings are inserted as text.', $file, $key);
                }
            }
        }
    }

    /** @return array<string, string>|null */
    private function argumentsOrNull(string $message): ?array
    {
        try {
            return IcuArguments::of($message);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<string, string> $arguments */
    private function describe(array $arguments): string
    {
        return implode(', ', array_map(static fn (string $name, string $kind): string => $name.':'.$kind, array_keys($arguments), $arguments));
    }
}
