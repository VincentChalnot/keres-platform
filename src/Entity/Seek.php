<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\ColorPreference;
use App\Model\MultiplayerLimits;
use App\Model\SeekStatus;
use App\Model\TimeControl;
use App\Repository\SeekRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A promise to play, not a game (04-matchmaking.md). `SeekMatcher` is the
 * only writer of `status`/`matchedGame` past construction, and it writes
 * them through raw DBAL, not this entity (sec 3.5) - the getters here exist
 * for listing/serialisation, not for the pairing transaction itself.
 */
#[ORM\Entity(repositoryClass: SeekRepository::class)]
#[ORM\Table(name: 'seek')]
class Seek
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $user;

    #[ORM\Embedded(class: TimeControl::class, columnPrefix: false)]
    private TimeControl $timeControl;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $speedCategoryValue = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $rated;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $colorPreferenceValue;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $ratingMin = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $ratingMax = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $autoWiden;

    #[ORM\Column(type: Types::INTEGER)]
    private int $ratingSnapshot;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $statusValue = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $lastHeartbeatAt;

    #[ORM\ManyToOne(targetEntity: Game::class)]
    #[ORM\JoinColumn(name: 'matched_game_id', nullable: true, onDelete: 'SET NULL')]
    private ?Game $matchedGame = null;

    public function __construct(
        User $user,
        TimeControl $timeControl,
        bool $rated,
        ColorPreference $colorPreference,
        bool $autoWiden,
        int $ratingSnapshot,
        \DateTimeImmutable $now,
        int $ttlSeconds,
        ?int $ratingMin = null,
        ?int $ratingMax = null,
    ) {
        $this->uuid = Uuid::v4();
        $this->user = $user;
        $this->timeControl = $timeControl;
        $this->speedCategoryValue = $timeControl->speedCategory()?->value;
        $this->rated = $rated;
        $this->colorPreferenceValue = $colorPreference->value;
        $this->autoWiden = $autoWiden;
        $this->ratingSnapshot = $ratingSnapshot;
        $this->ratingMin = $ratingMin;
        $this->ratingMax = $ratingMax;
        $this->createdAt = $now;
        $this->expiresAt = $now->modify(\sprintf('+%d seconds', $ttlSeconds));
        $this->lastHeartbeatAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTimeControl(): TimeControl
    {
        return $this->timeControl;
    }

    public function isRated(): bool
    {
        return $this->rated;
    }

    public function getColorPreference(): ColorPreference
    {
        return ColorPreference::from($this->colorPreferenceValue);
    }

    public function getRatingMin(): ?int
    {
        return $this->ratingMin;
    }

    public function getRatingMax(): ?int
    {
        return $this->ratingMax;
    }

    public function isAutoWiden(): bool
    {
        return $this->autoWiden;
    }

    public function getRatingSnapshot(): int
    {
        return $this->ratingSnapshot;
    }

    public function getStatus(): SeekStatus
    {
        return SeekStatus::from($this->statusValue);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getLastHeartbeatAt(): \DateTimeImmutable
    {
        return $this->lastHeartbeatAt;
    }

    public function getMatchedGame(): ?Game
    {
        return $this->matchedGame;
    }

    public function isOpen(): bool
    {
        return SeekStatus::OPEN === $this->getStatus();
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    /** True iff `$other` is the same (kind, initial, increment, days, rated) tuple - the create-time dedupe check (sec 6.2). */
    public function hasSameParameters(self $other): bool
    {
        return $this->timeControl->getKind() === $other->timeControl->getKind()
            && $this->timeControl->getInitialSeconds() === $other->timeControl->getInitialSeconds()
            && $this->timeControl->getIncrementSeconds() === $other->timeControl->getIncrementSeconds()
            && $this->timeControl->getDaysPerMove() === $other->timeControl->getDaysPerMove()
            && $this->rated === $other->rated
            && $this->getColorPreference() === $other->getColorPreference()
            && $this->autoWiden === $other->autoWiden
            && $this->ratingMin === $other->ratingMin
            && $this->ratingMax === $other->ratingMax;
    }

    /** Owner-only, one-shot (sec 2). Anything else touching `status`/`matchedGame` is `SeekMatcher`'s raw-DBAL job. */
    public function cancel(): void
    {
        $this->statusValue = SeekStatus::CANCELED->value;
    }

    public function heartbeat(\DateTimeImmutable $now): void
    {
        $this->lastHeartbeatAt = $now;
    }

    /** Current widening window - display/response use only; the server-side pairing predicate recomputes this itself (sec 3.3). */
    public function widenedWindow(\DateTimeImmutable $now): ?array
    {
        if (!$this->autoWiden) {
            return null;
        }

        $ageSeconds = max(0, $now->getTimestamp() - $this->createdAt->getTimestamp());
        $width = min(
            MultiplayerLimits::QUICK_PAIR_WINDOW_MAX,
            MultiplayerLimits::QUICK_PAIR_WINDOW_BASE + MultiplayerLimits::QUICK_PAIR_WIDEN_PER_SECOND * $ageSeconds,
        );

        return ['min' => $this->ratingSnapshot - $width, 'max' => $this->ratingSnapshot + $width];
    }
}
