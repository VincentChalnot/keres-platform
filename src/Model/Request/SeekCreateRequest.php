<?php

declare(strict_types=1);

namespace App\Model\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The shared seek body (09-api-reference.md sec 4.1). JSON endpoint, so no
 * `FormType`: an inline-constrained, `readonly` request DTO validated
 * through `ValidatorInterface`, per 00-overview.md sec 6's rule that JSON
 * bodies never carry `#[Assert\*]` on an *entity*. The cross-field
 * time-control rule (kind/initialSeconds/incrementSeconds/daysPerMove
 * coherence) is checked separately by the action - it needs a distinct
 * error code (`invalid_time_control`) from a per-field failure
 * (`validation_failed`).
 */
final readonly class SeekCreateRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['unlimited', 'realtime', 'correspondence'])]
        public string $kind,
        #[Assert\Range(min: 15, max: 10800)]
        public ?int $initialSeconds,
        #[Assert\Range(min: 0, max: 180)]
        public ?int $incrementSeconds,
        #[Assert\Choice(choices: [1, 3, 7])]
        public ?int $daysPerMove,
        public bool $rated,
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['white', 'black', 'random'])]
        public string $colorPreference,
        #[Assert\Range(min: 400, max: 3000)]
        public ?int $ratingMin,
        #[Assert\Range(min: 400, max: 3000)]
        public ?int $ratingMax,
        public bool $autoWiden = false,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            kind: \is_string($data['kind'] ?? null) ? $data['kind'] : '',
            initialSeconds: self::intOrNull($data['initialSeconds'] ?? null),
            incrementSeconds: self::intOrNull($data['incrementSeconds'] ?? null),
            daysPerMove: self::intOrNull($data['daysPerMove'] ?? null),
            rated: (bool) ($data['rated'] ?? false),
            colorPreference: \is_string($data['colorPreference'] ?? null) ? $data['colorPreference'] : '',
            ratingMin: self::intOrNull($data['ratingMin'] ?? null),
            ratingMax: self::intOrNull($data['ratingMax'] ?? null),
            autoWiden: (bool) ($data['autoWiden'] ?? false),
        );
    }

    private static function intOrNull(mixed $value): ?int
    {
        return \is_int($value) ? $value : null;
    }
}
