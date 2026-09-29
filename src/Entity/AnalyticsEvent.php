<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\AnalyticsEventType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * T6: append-only instrumentation log (collection only - no dashboards, no
 * aggregation UI, nothing reads this yet). `payload` is denormalised on
 * purpose, same reasoning as `Notification::payload`: no join needed to
 * make sense of a historical row, and a later schema change to `Game`/
 * `User` never retroactively changes what an old event says happened.
 *
 * `game` is deliberately a raw UUID value, not a managed relation: this
 * entity is written from the hottest path in the app (a move played on
 * every single ply, via `RecordAnalyticsEventMessage`/T1's async worker),
 * and a managed relation would risk an accidental join or a lock on the
 * `game` row. `user` *is* a managed (nullable) relation - written via
 * `EntityManagerInterface::getReference()` in the handler, which sets the
 * FK column without a SELECT, so it stays cheap while still being a real
 * FK for anyone who later wants to join on it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'analytics_event')]
#[ORM\Index(name: 'idx_analytics_event_type_occurred', columns: ['type', 'occurred_at'])]
#[ORM\Index(name: 'idx_analytics_event_user', columns: ['user_id'])]
class AnalyticsEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: AnalyticsEventType::class)]
    private AnalyticsEventType $type;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $game;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    /** @param array<string, mixed> $payload */
    public function __construct(
        AnalyticsEventType $type,
        \DateTimeImmutable $occurredAt,
        ?User $user = null,
        ?Uuid $game = null,
        array $payload = [],
    ) {
        $this->type = $type;
        $this->occurredAt = $occurredAt;
        $this->user = $user;
        $this->game = $game;
        $this->payload = $payload;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): AnalyticsEventType
    {
        return $this->type;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getGame(): ?Uuid
    {
        return $this->game;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
