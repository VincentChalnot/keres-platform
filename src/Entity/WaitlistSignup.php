<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WaitlistSignupRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * T7: physical-edition waitlist, double opt-in. No `Feedback(WAITLIST, ...)`
 * row is created until the recipient actually clicks the confirmation link
 * (`WaitlistConfirmAction`) - an unconfirmed signup lives only here, so a
 * submitted-but-never-verified email address never reaches the admin
 * review queue. `tokenHash`/`expiresAt` follow the exact same pattern as
 * `User::resetToken`/`resetTokenExpiresAt` (see `LostPasswordAction`/
 * `ResetPasswordAction`) rather than a new scheme.
 */
#[ORM\Entity(repositoryClass: WaitlistSignupRepository::class)]
#[ORM\Table(name: 'waitlist_signup')]
#[ORM\Index(name: 'idx_waitlist_signup_token_hash', columns: ['token_hash'])]
class WaitlistSignup
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $email;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $tokenHash;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    public function __construct(string $email, ?string $name, ?string $note, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $this->id = Uuid::v4();
        $this->email = $email;
        $this->name = $name;
        $this->note = $note;
        $this->tokenHash = $tokenHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function confirm(): void
    {
        $this->confirmedAt = new \DateTimeImmutable();
    }
}
