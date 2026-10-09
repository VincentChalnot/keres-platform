<?php

declare(strict_types=1);

namespace App\Service\Translation;

/**
 * The arguments an ICU MessageFormat string uses, with the kind of each
 * (`simple` `{name}`, `number`, `plural`, `select`), found by a small parser
 * of the subset the catalogues are allowed to use - the very subset the
 * TypeScript formatter (`assets/typescript/src/i18n`) implements: simple
 * arguments, `number`, `plural` (`=N`, zero/one/two/few/many/other, `#`) and
 * `select`, with ICU's apostrophe quoting. It exists so that
 * `TranslationCatalogueChecker` can assert that a key takes the same
 * arguments in every language.
 */
final class IcuArguments
{
    public const string SIMPLE = 'simple';
    public const string NUMBER = 'number';
    public const string PLURAL = 'plural';
    public const string SELECT = 'select';

    private int $position = 0;

    /** @var array<string, string> */
    private array $arguments = [];

    private function __construct(
        private readonly string $message,
    ) {
    }

    /**
     * @return array<string, string> argument name => kind, sorted by name
     *
     * @throws \InvalidArgumentException when the message is not valid in the supported subset
     */
    public static function of(string $message): array
    {
        $parser = new self($message);
        $parser->sequence(false);

        if ($parser->position < \strlen($message)) {
            throw new \InvalidArgumentException(\sprintf('Unmatched "}" at offset %d.', $parser->position));
        }

        ksort($parser->arguments);

        return $parser->arguments;
    }

    /** Parses text up to the end of the message or, when nested, up to the `}` closing the branch. */
    private function sequence(bool $nested): void
    {
        $length = \strlen($this->message);

        while ($this->position < $length) {
            $char = $this->message[$this->position];

            if ("'" === $char) {
                $this->quote($nested);
            } elseif ('{' === $char) {
                ++$this->position;
                $this->argument();
            } elseif ('}' === $char) {
                if ($nested) {
                    return;
                }

                throw new \InvalidArgumentException(\sprintf('Unmatched "}" at offset %d.', $this->position));
            } else {
                ++$this->position;
            }
        }

        if ($nested) {
            throw new \InvalidArgumentException('Unterminated plural/select branch.');
        }
    }

    /** ICU's default apostrophe mode: `''` is one apostrophe, `'{` starts a quoted run, any other `'` is literal. */
    private function quote(bool $nested): void
    {
        $next = $this->message[$this->position + 1] ?? '';

        if ("'" === $next) {
            $this->position += 2;

            return;
        }

        if ('{' !== $next && '}' !== $next && !($nested && '#' === $next)) {
            ++$this->position;

            return;
        }

        $this->position += 2;
        $length = \strlen($this->message);

        while ($this->position < $length) {
            if ("'" === $this->message[$this->position]) {
                if ("'" === ($this->message[$this->position + 1] ?? '')) {
                    $this->position += 2;

                    continue;
                }

                ++$this->position;

                return;
            }

            ++$this->position;
        }
    }

    private function argument(): void
    {
        $name = $this->word();

        if ('' === $name) {
            throw new \InvalidArgumentException(\sprintf('Missing argument name at offset %d.', $this->position));
        }

        $this->skipSpaces();

        if ($this->consume('}')) {
            $this->record($name, self::SIMPLE);

            return;
        }

        if (!$this->consume(',')) {
            throw new \InvalidArgumentException(\sprintf('Expected "," or "}" after "%s".', $name));
        }

        $this->skipSpaces();
        $type = $this->word();
        $this->skipSpaces();

        if (self::NUMBER === $type) {
            $this->record($name, self::NUMBER);

            if ($this->consume(',')) {
                $this->skipUntilClosingBrace();
            }

            if (!$this->consume('}')) {
                throw new \InvalidArgumentException(\sprintf('Unterminated number argument "%s".', $name));
            }

            return;
        }

        if (self::PLURAL !== $type && self::SELECT !== $type) {
            throw new \InvalidArgumentException(\sprintf('Unsupported argument type "%s" for "%s" (use simple, number, plural or select).', $type, $name));
        }

        $this->record($name, $type);

        if (!$this->consume(',')) {
            throw new \InvalidArgumentException(\sprintf('Expected "," after "%s, %s".', $name, $type));
        }

        $hasOther = false;

        while (true) {
            $this->skipSpaces();

            if ($this->consume('}')) {
                break;
            }

            $selector = $this->selector();
            $this->skipSpaces();

            if ('' === $selector || !$this->consume('{')) {
                throw new \InvalidArgumentException(\sprintf('Expected a selector and "{" in "%s, %s".', $name, $type));
            }

            $hasOther = $hasOther || 'other' === $selector;
            $this->sequence(true);
            $this->consume('}');
        }

        if (!$hasOther) {
            throw new \InvalidArgumentException(\sprintf('"%s, %s" has no "other" branch.', $name, $type));
        }
    }

    private function record(string $name, string $kind): void
    {
        $current = $this->arguments[$name] ?? self::SIMPLE;
        $this->arguments[$name] = self::SIMPLE === $current ? $kind : $current;
    }

    private function word(): string
    {
        $start = $this->position;

        while (1 === preg_match('/[A-Za-z0-9_]/', $this->message[$this->position] ?? '')) {
            ++$this->position;
        }

        return substr($this->message, $start, $this->position - $start);
    }

    private function selector(): string
    {
        $start = $this->position;

        while ($this->position < \strlen($this->message) && 1 !== preg_match('/[\s{}]/', $this->message[$this->position])) {
            ++$this->position;
        }

        return substr($this->message, $start, $this->position - $start);
    }

    private function skipSpaces(): void
    {
        while (1 === preg_match('/\s/', $this->message[$this->position] ?? '')) {
            ++$this->position;
        }
    }

    private function skipUntilClosingBrace(): void
    {
        while ($this->position < \strlen($this->message) && '}' !== $this->message[$this->position]) {
            ++$this->position;
        }
    }

    private function consume(string $char): bool
    {
        if (($this->message[$this->position] ?? '') !== $char) {
            return false;
        }

        ++$this->position;

        return true;
    }
}
