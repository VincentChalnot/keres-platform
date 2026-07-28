<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\FriendshipStatus;
use App\Repository\FriendshipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One directed row per relation (05-social.md sec 3.1, 01-domain-model.md
 * sec 6.7). Direction is a historical record of who asked; an `ACCEPTED`
 * row is semantically undirected and every read treats it as such (sec
 * 3.6). A `BLOCKED` row is strictly directional (F2) - the blocker is
 * always `requester`.
 */
#[ORM\Entity(repositoryClass: FriendshipRepository::class)]
#[ORM\Table(name: 'friendship')]
#[ORM\UniqueConstraint(name: 'uniq_friendship_pair', columns: ['requester_id', 'addressee_id'])]
#[ORM\Index(name: 'idx_friendship_addressee_status', columns: ['addressee_id', 'status_value'])]
#[ORM\Index(name: 'idx_friendship_requester_status', columns: ['requester_id', 'status_value'])]
class Friendship
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'requester_id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $requester;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'addressee_id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $addressee;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $statusValue;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $respondedAt = null;

    public function __construct(User $requester, User $addressee, FriendshipStatus $status, \DateTimeImmutable $now)
    {
        $this->requester = $requester;
        $this->addressee = $addressee;
        $this->statusValue = $status->value;
        $this->createdAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequester(): User
    {
        return $this->requester;
    }

    public function getAddressee(): User
    {
        return $this->addressee;
    }

    public function getOther(User $viewer): User
    {
        return $this->requester === $viewer ? $this->addressee : $this->requester;
    }

    public function getStatus(): FriendshipStatus
    {
        return FriendshipStatus::from($this->statusValue);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRespondedAt(): ?\DateTimeImmutable
    {
        return $this->respondedAt;
    }

    /** T3/T4/T7 - `status=ACCEPTED|DECLINED|PENDING`, `responded_at` (null only for the T7 re-request). */
    public function transitionTo(FriendshipStatus $status, ?\DateTimeImmutable $respondedAt, ?\DateTimeImmutable $createdAt = null): void
    {
        $this->statusValue = $status->value;
        $this->respondedAt = $respondedAt;

        if (null !== $createdAt) {
            $this->createdAt = $createdAt;
        }
    }
}
