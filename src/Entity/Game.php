<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\GameEndReason;
use App\Model\MovesData;
use App\Model\MultiplayerLimits;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Model\SpeedCategory;
use App\Model\TimeControl;
use App\Model\TimeControlKind;
use App\Repository\GameRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GameRepository::class)]
#[ORM\Table(name: 'game')]
#[ORM\Index(
    name: 'idx_game_move_deadline',
    columns: ['move_deadline_at'],
    options: ['where' => 'move_deadline_at IS NOT NULL AND game_over_at IS NULL'],
)]
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

    #[ORM\Embedded(class: TimeControl::class, columnPrefix: false)]
    private TimeControl $timeControl;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $speedCategoryValue = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $rated = false;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $endReasonValue = GameEndReason::NONE->value;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $gameOverAt = null;

    #[ORM\Column(type: 'timestamptz_micro', nullable: true)]
    private ?\DateTimeImmutable $clockTurnStartedAt = null;

    #[ORM\Column(type: 'timestamptz_micro', nullable: true)]
    private ?\DateTimeImmutable $moveDeadlineAt = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $drawOfferedByColorValue = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $rematchOfferedByColorValue = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $whiteWins = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $draw = false;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
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

    public function __construct(User $createdBy, OpponentType $opponentType, TimeControl $timeControl, bool $rated)
    {
        $this->uuid = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->gameMoves = new ArrayCollection();
        $this->players = new ArrayCollection();
        $this->createdBy = $createdBy;
        $this->opponentTypeValue = $opponentType->value;
        $this->timeControl = $timeControl;
        $this->rated = $rated;
        $this->speedCategoryValue = $timeControl->speedCategory()?->value;
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

    public function getTimeControl(): TimeControl
    {
        return $this->timeControl;
    }

    public function getSpeedCategory(): ?SpeedCategory
    {
        return null === $this->speedCategoryValue ? null : SpeedCategory::from($this->speedCategoryValue);
    }

    public function isRated(): bool
    {
        return $this->rated;
    }

    public function getEndReason(): GameEndReason
    {
        return GameEndReason::from($this->endReasonValue);
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

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    /** ClockManager::arm() only - the `created` -> `ongoing` transition anchor. */
    public function setStartedAt(?\DateTimeImmutable $startedAt): void
    {
        $this->startedAt = $startedAt;
    }

    public function getGameOverAt(): ?\DateTimeImmutable
    {
        return $this->gameOverAt;
    }

    public function getClockTurnStartedAt(): ?\DateTimeImmutable
    {
        return $this->clockTurnStartedAt;
    }

    /** ClockManager only (03-time-control.md sec 2.4: the only writer). */
    public function setClockTurnStartedAt(?\DateTimeImmutable $clockTurnStartedAt): void
    {
        $this->clockTurnStartedAt = $clockTurnStartedAt;
    }

    public function getMoveDeadlineAt(): ?\DateTimeImmutable
    {
        return $this->moveDeadlineAt;
    }

    /** ClockManager only (03-time-control.md sec 2.4: the only writer). */
    public function setMoveDeadlineAt(?\DateTimeImmutable $moveDeadlineAt): void
    {
        $this->moveDeadlineAt = $moveDeadlineAt;
    }

    public function getDrawOfferedByColor(): ?PieceColor
    {
        return null === $this->drawOfferedByColorValue ? null : PieceColor::from($this->drawOfferedByColorValue);
    }

    public function setDrawOfferedByColor(?PieceColor $color): void
    {
        $this->drawOfferedByColorValue = $color?->value;
    }

    public function getRematchOfferedByColor(): ?PieceColor
    {
        return null === $this->rematchOfferedByColorValue ? null : PieceColor::from($this->rematchOfferedByColorValue);
    }

    public function setRematchOfferedByColor(?PieceColor $color): void
    {
        $this->rematchOfferedByColorValue = $color?->value;
    }

    public function isWhiteWins(): bool
    {
        return $this->whiteWins;
    }

    public function isDraw(): bool
    {
        return $this->draw;
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

    /**
     * Invariant 3 clause 4 / 03-time-control.md sec 7.2: min(whitePlies,
     * blackPlies) >= RATED_MIN_PLIES, i.e. both sides have moved at least
     * twice. The exact negation of this predicate is the abort window - one
     * shared method so they cannot drift.
     */
    public function hasReachedRatedPlyFloor(): bool
    {
        $count = $this->gameMoves->count();
        $whitePlies = intdiv($count + 1, 2);
        $blackPlies = intdiv($count, 2);

        return min($whitePlies, $blackPlies) >= MultiplayerLimits::RATED_MIN_PLIES;
    }

    /**
     * Invariant 3, made executable (06-rating.md sec 6.1). Never
     * short-circuited on `$this->rated` alone - all six conjuncts are
     * re-evaluated from scratch at finish, which is what lets a rematch
     * inherit `rated` verbatim from the finished game (05-social.md sec 7).
     * Pure and side-effect-free: no Doctrine query, only the already-loaded
     * `$players`/`$gameMoves` collections.
     */
    public function isRatedOutcome(): bool
    {
        if (!$this->rated) {
            return false; // (1) consent
        }

        if (TimeControlKind::UNLIMITED === $this->timeControl->getKind()) {
            return false; // (2) no pool to write to
        }

        $white = $this->getPlayer(PieceColor::WHITE)->getUser();
        $black = $this->getPlayer(PieceColor::BLACK)->getUser();

        if (null === $white || null === $black) {
            return false; // (3a) engine opponent
        }

        if ($white === $black) {
            return false; // (3b) hot-seat, same user both colours
        }

        if (!$this->hasReachedRatedPlyFloor()) {
            return false; // (4)
        }

        return !\in_array($this->getEndReason(), [GameEndReason::NONE, GameEndReason::ABORTED], true); // (5)
    }

    /** 03-time-control.md sec 7.2: a game may be aborted exactly while it would not have counted anyway. */
    public function isAbortable(): bool
    {
        return null === $this->gameOverAt && !$this->hasReachedRatedPlyFloor();
    }

    /**
     * Invariant 5: write-once. Replaces setGameOverAt/setWhiteWins/setDraw -
     * the only path permitted to finalise a game (03-time-control.md sec 5.6,
     * 01-domain-model.md sec 4.3). Callers own clock finalisation
     * (ClockManager::stop()) themselves; this only writes the result.
     */
    public function finish(GameEndReason $reason, ?PieceColor $winner): void
    {
        if (null !== $this->gameOverAt) {
            throw new \LogicException('A finished game is never reopened.');
        }

        $this->gameOverAt = new \DateTimeImmutable();
        $this->endReasonValue = $reason->value;
        // Abort has no result at all - distinct from a draw, even though
        // both leave $winner null (06-rating.md sec 6.2: ABORTED writes
        // whiteWins = false, draw = false).
        $this->draw = GameEndReason::ABORTED !== $reason && null === $winner;
        $this->whiteWins = PieceColor::WHITE === $winner;
        $this->moveDeadlineAt = null;
        $this->clockTurnStartedAt = null;
        $this->drawOfferedByColorValue = null;
    }

    /**
     * AI/hot-seat only. D8/09-api-reference.md restrict Undo to those modes;
     * invariant 5 ("a finished game is never reopened") governs `finish()`
     * and every clocked/rated path, not this one. Reverses exactly what
     * `finish()` sets. The caller is responsible for the opponent-type gate.
     */
    public function reopenForUndo(): void
    {
        $this->gameOverAt = null;
        $this->endReasonValue = GameEndReason::NONE->value;
        $this->whiteWins = false;
        $this->draw = false;
    }
}
