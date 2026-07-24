<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserPreferencesRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;

/**
 * Player-configurable settings, kept out of the `user` table so that
 * account identity (User) and preferences can evolve independently.
 */
#[ORM\Entity(repositoryClass: UserPreferencesRepository::class)]
#[ORM\Table(name: 'user_preferences')]
#[UniqueEntity(fields: ['identifier'], message: 'This identifier is already taken.')]
class UserPreferences
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, unique: true)]
    private User $user;

    #[ORM\Column(type: Types::STRING, length: 32, unique: true, nullable: true)]
    private ?string $identifier = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(type: Types::STRING, length: 8, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column(type: Types::STRING, length: 2, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $newsletterOptIn = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $showBoardCoordinates = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $showOpponentThreatsOnHover = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $allowContactByEmail = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $searchableByOtherUsers = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->id = Uuid::v4();
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function setIdentifier(?string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function hasIdentifier(): bool
    {
        return null !== $this->identifier && '' !== $this->identifier;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): void
    {
        $this->firstName = $firstName;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): void
    {
        $this->lastName = $lastName;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): void
    {
        $this->locale = $locale;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): void
    {
        $this->country = $country;
    }

    public function isNewsletterOptIn(): bool
    {
        return $this->newsletterOptIn;
    }

    public function setNewsletterOptIn(bool $newsletterOptIn): void
    {
        $this->newsletterOptIn = $newsletterOptIn;
    }

    public function isShowBoardCoordinates(): bool
    {
        return $this->showBoardCoordinates;
    }

    public function setShowBoardCoordinates(bool $showBoardCoordinates): void
    {
        $this->showBoardCoordinates = $showBoardCoordinates;
    }

    public function isShowOpponentThreatsOnHover(): bool
    {
        return $this->showOpponentThreatsOnHover;
    }

    public function setShowOpponentThreatsOnHover(bool $showOpponentThreatsOnHover): void
    {
        $this->showOpponentThreatsOnHover = $showOpponentThreatsOnHover;
    }

    public function isAllowContactByEmail(): bool
    {
        return $this->allowContactByEmail;
    }

    public function setAllowContactByEmail(bool $allowContactByEmail): void
    {
        $this->allowContactByEmail = $allowContactByEmail;
    }

    public function isSearchableByOtherUsers(): bool
    {
        return $this->searchableByOtherUsers;
    }

    public function setSearchableByOtherUsers(bool $searchableByOtherUsers): void
    {
        $this->searchableByOtherUsers = $searchableByOtherUsers;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
