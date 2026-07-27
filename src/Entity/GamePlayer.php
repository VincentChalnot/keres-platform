<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\PieceColor;
use App\Repository\GamePlayerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GamePlayerRepository::class)]
#[ORM\Table(name: 'game_player')]
#[ORM\UniqueConstraint(name: 'uniq_game_player_game_color', columns: ['game_id', 'color'])]
#[ORM\Index(name: 'idx_game_player_user_game', columns: ['user_id', 'game_id'])]
class GamePlayer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'players')]
    #[ORM\JoinColumn(name: 'game_id', nullable: false, onDelete: 'CASCADE')]
    private readonly Game $game;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $colorValue;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: true)]
    private readonly ?User $user;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $clockMsRemaining = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $ratingBefore = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $ratingDeviationBefore = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $ratingAfter = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $provisionalBefore = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $drawOfferDeclinedAtPly = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $hiddenAt = null;

    public function __construct(
        Game $game,
        PieceColor $color,
        ?User $user,
        ?int $clockMsRemaining = null,
    ) {
        $this->game = $game;
        $this->colorValue = $color->value;
        $this->user = $user;
        $this->clockMsRemaining = $clockMsRemaining;
        $game->addPlayer($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGame(): Game
    {
        return $this->game;
    }

    public function getColor(): PieceColor
    {
        return PieceColor::from($this->colorValue);
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function isEngine(): bool
    {
        return null === $this->user;
    }

    public function isHumanUser(?User $user): bool
    {
        return null !== $user && $this->user === $user;
    }

    public function getClockMsRemaining(): ?int
    {
        return $this->clockMsRemaining;
    }

    public function setClockMsRemaining(?int $clockMsRemaining): void
    {
        $this->clockMsRemaining = $clockMsRemaining;
    }

    public function getRatingBefore(): ?int
    {
        return $this->ratingBefore;
    }

    public function getRatingDeviationBefore(): ?int
    {
        return $this->ratingDeviationBefore;
    }

    public function getRatingAfter(): ?int
    {
        return $this->ratingAfter;
    }

    public function getProvisionalBefore(): ?bool
    {
        return $this->provisionalBefore;
    }

    public function getHiddenAt(): ?\DateTimeImmutable
    {
        return $this->hiddenAt;
    }

    public function setHiddenAt(?\DateTimeImmutable $hiddenAt): void
    {
        $this->hiddenAt = $hiddenAt;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): void
    {
        $this->lastSeenAt = $lastSeenAt;
    }
}
