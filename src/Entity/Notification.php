<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\Notification\NotificationType;
use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One in-app notification (01-domain-model.md sec 4.9, 07-notifications.md
 * sec 7). `payload` is denormalised on purpose: the inbox renders a line
 * without a join, and a renamed/deleted actor never blanks a historical
 * row. `subject` ("game:<uuid>", "user:<uuid>") groups rows about the same
 * thing, so a new "your turn" can collapse into an unread one and opening
 * a game can mark its rows read.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
#[ORM\UniqueConstraint(name: 'uniq_notification_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_notification_inbox', columns: ['user_id', 'read_at', 'created_at'])]
#[ORM\Index(name: 'idx_notification_subject', columns: ['user_id', 'subject'])]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: NotificationType::class)]
    private NotificationType $type;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $subject;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $payload */
    public function __construct(User $user, NotificationType $type, array $payload, ?string $subject, \DateTimeImmutable $createdAt)
    {
        $this->uuid = Uuid::v4();
        $this->user = $user;
        $this->type = $type;
        $this->payload = $payload;
        $this->subject = $subject;
        $this->createdAt = $createdAt;
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

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return null !== $this->readAt;
    }

    public function markRead(\DateTimeImmutable $now): void
    {
        $this->readAt ??= $now;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Collapses a repeat event into this still-unread row (e.g. a second
     * "your turn" in the same game) instead of stacking a new one.
     *
     * @param array<string, mixed> $payload
     */
    public function refresh(array $payload, \DateTimeImmutable $now): void
    {
        $this->payload = $payload;
        $this->createdAt = $now;
    }
}
