<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Log row for a failed outgoing email (Symfony Mailer's FailedMessageEvent),
 * persisted by MailerFailureListener. Read-only from the admin panel — see
 * config/admin/MailerError.yaml and config/datagrid/MailerError.yaml.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mailer_error')]
#[ORM\Index(name: 'idx_mailer_error_created_at', columns: ['created_at'])]
class MailerError
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $errorClass;

    #[ORM\Column(type: Types::TEXT)]
    private string $errorMessage;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $subject;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $recipients;

    public function __construct(string $errorClass, string $errorMessage, ?string $subject, ?string $recipients)
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->errorClass = $errorClass;
        $this->errorMessage = $errorMessage;
        $this->subject = $subject;
        $this->recipients = $recipients;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getErrorClass(): string
    {
        return $this->errorClass;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function getRecipients(): ?string
    {
        return $this->recipients;
    }
}
