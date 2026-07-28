<?php

declare(strict_types=1);

namespace App\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Embedded verbatim (columnPrefix: false) on `game` so its four columns keep
 * identical names wherever it is embedded - 01-domain-model.md sec 3.1.
 */
#[ORM\Embeddable]
class TimeControl
{
    #[ORM\Column(name: 'time_control_kind', type: Types::SMALLINT, options: ['default' => 0])]
    private int $kindValue = 0;

    #[ORM\Column(name: 'initial_seconds', type: Types::INTEGER, nullable: true)]
    private ?int $initialSeconds = null;

    #[ORM\Column(name: 'increment_seconds', type: Types::INTEGER, nullable: true)]
    private ?int $incrementSeconds = null;

    #[ORM\Column(name: 'days_per_move', type: Types::INTEGER, nullable: true)]
    private ?int $daysPerMove = null;

    private function __construct()
    {
    }

    public static function unlimited(): self
    {
        return new self();
    }

    public static function realtime(int $initialSeconds, int $incrementSeconds): self
    {
        if ($initialSeconds < 0 || $incrementSeconds < 0 || ($initialSeconds + $incrementSeconds) <= 0) {
            throw new \InvalidArgumentException('Real-time control must allow at least one second of play.');
        }

        $tc = new self();
        $tc->kindValue = TimeControlKind::REALTIME->value;
        $tc->initialSeconds = $initialSeconds;
        $tc->incrementSeconds = $incrementSeconds;

        return $tc;
    }

    public static function correspondence(int $daysPerMove): self
    {
        if ($daysPerMove < 1) {
            throw new \InvalidArgumentException('Correspondence needs at least one day per move.');
        }

        $tc = new self();
        $tc->kindValue = TimeControlKind::CORRESPONDENCE->value;
        $tc->daysPerMove = $daysPerMove;

        return $tc;
    }

    public function getKind(): TimeControlKind
    {
        return TimeControlKind::from($this->kindValue);
    }

    public function getInitialSeconds(): ?int
    {
        return $this->initialSeconds;
    }

    public function getIncrementSeconds(): ?int
    {
        return $this->incrementSeconds;
    }

    public function getDaysPerMove(): ?int
    {
        return $this->daysPerMove;
    }

    /** Contract: estimated = initial + 40 * increment. REALTIME only. */
    public function estimatedSeconds(): ?int
    {
        return TimeControlKind::REALTIME === $this->getKind()
            ? $this->initialSeconds + 40 * $this->incrementSeconds
            : null;
    }

    public function speedCategory(): ?SpeedCategory
    {
        return match ($this->getKind()) {
            TimeControlKind::UNLIMITED => null,
            TimeControlKind::CORRESPONDENCE => SpeedCategory::CORRESPONDENCE,
            TimeControlKind::REALTIME => match (true) {
                $this->estimatedSeconds() < 180 => SpeedCategory::BULLET,
                $this->estimatedSeconds() < 480 => SpeedCategory::BLITZ,
                $this->estimatedSeconds() < 1500 => SpeedCategory::RAPID,
                default => SpeedCategory::CLASSICAL,
            },
        };
    }

    /** Necessary, NOT sufficient - see invariant 3 and 06-rating.md sec 2. */
    public function isRatable(): bool
    {
        return TimeControlKind::UNLIMITED !== $this->getKind();
    }
}
