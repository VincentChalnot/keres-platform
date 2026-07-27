<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\MovesData;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Repository\GameRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GameRepository::class)]
#[ORM\Table(name: 'game')]
class Game
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    // Not `readonly`: Doctrine's readonly-property accessor compares hydrated
    // values with `!==`, which for a value object is identity comparison -
    // any refresh() or find(..., LockMode::PESSIMISTIC_*) on an already-managed
    // Game (see GameEngine::applyMove()) re-hydrates a *new* Uuid instance for
    // the same value and throws "Attempting to change readonly property".
    // Immutability is still enforced by convention: no setter exists.
    private Uuid $uuid;

    #[ORM\Column(type: Types::INTEGER)]
    private int $opponentTypeValue;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $gameOverAt = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $whiteWins = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $draw = false;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false)]
    private User $createdBy;

    /**
     * @var Collection<int, GamePlayer>
     */
    #[ORM\OneToMany(targetEntity: GamePlayer::class, mappedBy: 'game', cascade: [
        'persist',
        'remove',
    ], orphanRemoval: true)]
    #[ORM\OrderBy(['colorValue' => 'ASC'])]
    private Collection $players;

    /**
     * @var Collection<int, GameMove>
     */
    #[ORM\OneToMany(targetEntity: GameMove::class, mappedBy: 'game', cascade: [
        'persist',
        'remove',
    ], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $gameMoves;

    public function __construct(User $createdBy, OpponentType $opponentType = OpponentType::AI)
    {
        $this->uuid = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->gameMoves = new ArrayCollection();
        $this->players = new ArrayCollection();
        $this->opponentTypeValue = $opponentType->value;
        $this->createdBy = $createdBy;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getOpponentType(): OpponentType
    {
        return OpponentType::from($this->opponentTypeValue);
    }

    public function setOpponentType(OpponentType $opponentType): self
    {
        $this->opponentTypeValue = $opponentType->value;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getGameOverAt(): ?\DateTimeImmutable
    {
        return $this->gameOverAt;
    }

    public function setGameOverAt(?\DateTimeImmutable $gameOverAt): self
    {
        $this->gameOverAt = $gameOverAt;

        return $this;
    }

    public function isWhiteWins(): bool
    {
        return $this->whiteWins;
    }

    public function setWhiteWins(bool $whiteWins): self
    {
        $this->whiteWins = $whiteWins;

        return $this;
    }

    public function isDraw(): bool
    {
        return $this->draw;
    }

    public function setDraw(bool $draw): self
    {
        $this->draw = $draw;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * @return Collection<int, GamePlayer>
     */
    public function getPlayers(): Collection
    {
        return $this->players;
    }

    public function addPlayer(GamePlayer $player): void
    {
        if ($this->players->count() >= 2) {
            throw new \LogicException('A game has exactly two players.');
        }

        foreach ($this->players as $existing) {
            if ($existing->getColor() === $player->getColor()) {
                throw new \LogicException('Colour already taken.');
            }
        }
        $this->players->add($player);
    }

    public function getPlayer(PieceColor $color): GamePlayer
    {
        foreach ($this->players as $player) {
            if ($player->getColor() === $color) {
                return $player;
            }
        }

        throw new \LogicException('No player for colour '.$color->name);
    }

    /**
     * Returns the colours the given user plays in this game.
     * Hot-seat returns both colours; every other mode returns 0 or 1 entries.
     *
     * @return list<PieceColor>
     */
    public function getColorsForUser(?User $user): array
    {
        if (null === $user) {
            return [];
        }

        $colors = [];

        foreach ($this->players as $player) {
            if ($player->isHumanUser($user)) {
                $colors[] = $player->getColor();
            }
        }

        return $colors;
    }

    public function isParticipant(?User $user): bool
    {
        return [] !== $this->getColorsForUser($user);
    }

    /**
     * In AI/hot-seat mode, returns the human player's colour.
     * Returns null for multiplayer games (call getColorsForUser instead).
     */
    public function getCreatorColor(): PieceColor
    {
        return $this->getColorsForUser($this->createdBy)[0]
            ?? throw new \LogicException('Creator is not a player.');
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): self
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function isGameOver(): bool
    {
        return null !== $this->gameOverAt;
    }

    /**
     * @return Collection<int, GameMove>
     */
    public function getGameMoves(): Collection
    {
        return $this->gameMoves;
    }

    public function isWhiteTurn(): bool
    {
        return 0 === $this->gameMoves->count() % 2;
    }

    public function addMove(Move $move): GameMove
    {
        $moveEntity = new GameMove($this, $move);
        $this->gameMoves->add($moveEntity);

        return $moveEntity;
    }

    public function getLastMoveAt(): ?\DateTimeImmutable
    {
        $lastMove = $this->gameMoves->last();

        if (false === $lastMove) {
            return null;
        }

        return $lastMove->getCreatedAt();
    }

    public function getMovesData(): MovesData
    {
        $data = new MovesData();

        foreach ($this->gameMoves as $moveEntity) {
            $data->addMove($moveEntity->getMove()->getMoveData());
        }

        return $data;
    }
}
