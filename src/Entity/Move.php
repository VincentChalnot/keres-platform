<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\MoveData;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'move')]
#[ORM\UniqueConstraint(name: 'move_unique_idx', fields: ['moveData', 'fromBoardPosition'])]
class Move
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\Column(type: Types::BINARY, length: 2)]
    private readonly string $moveData;

    #[ORM\ManyToOne(targetEntity: BoardPosition::class)]
    #[ORM\JoinColumn(nullable: false)]
    private readonly BoardPosition $fromBoardPosition;

    #[ORM\ManyToOne(targetEntity: BoardPosition::class)]
    #[ORM\JoinColumn(nullable: false)]
    private readonly BoardPosition $toBoardPosition;

    /**
     * Engine (level 10) evaluation of the position after this move, White's
     * point of view, engine units. Lives on the move edge, not on the
     * BoardPosition: the board is shared by every line reaching it, the
     * verdict is not (repetition history, no-capture counter). Null = not
     * evaluated yet.
     *
     * The only mutable column of an otherwise immutable edge: it is filled in
     * after the fact by the async `EvaluateMoveHandler`, never on the
     * move-submission path.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $evaluation = null;

    public function __construct(MoveData $moveData, BoardPosition $fromBoardPosition, BoardPosition $toBoardPosition)
    {
        $this->moveData = $moveData->data;
        $this->fromBoardPosition = $fromBoardPosition;
        $this->toBoardPosition = $toBoardPosition;
    }

    public function getMoveData(): MoveData
    {
        return new MoveData($this->moveData);
    }

    public function getFromBoardPosition(): BoardPosition
    {
        return $this->fromBoardPosition;
    }

    public function getToBoardPosition(): BoardPosition
    {
        return $this->toBoardPosition;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getEvaluation(): ?int
    {
        return $this->evaluation;
    }

    public function setEvaluation(int $evaluation): void
    {
        $this->evaluation = $evaluation;
    }
}
