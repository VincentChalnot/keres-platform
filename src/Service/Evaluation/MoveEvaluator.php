<?php

declare(strict_types=1);

namespace App\Service\Evaluation;

use App\Engine\EngineApi;
use App\Entity\Game;
use App\Entity\GameMove;
use App\Model\MovesData;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Computes and caches the engine evaluation of a position *of a given game
 * line*. The cache is `Move.evaluation` (never `BoardPosition`): the verdict
 * depends on the whole line (repetition history, no-capture counter), and a
 * board is shared by many lines whereas a move edge is reached through a
 * specific previous board + move. A cached edge is therefore reused as-is
 * across games - the engine is only ever called for an edge not yet seen.
 *
 * "Ply" = number of moves played: ply 0 is the start position (no move, hence
 * no stored evaluation - it is balanced by definition, 0), ply N is the
 * position after the Nth move, whose evaluation sits on that move's edge.
 *
 * `evaluatePly()` / `evaluateAll()` call the engine and therefore only belong
 * in a worker (`EvaluateMoveHandler`) or a console command; request handlers
 * read `storedEvaluations()` and ask `EvaluationScheduler` for the rest.
 */
readonly class MoveEvaluator
{
    public function __construct(
        private EngineApi $engineApi,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param int $ply 0..number of moves played
     *
     * @return int White's point of view, engine units
     */
    public function evaluatePly(Game $game, int $ply): int
    {
        if (0 === $ply) {
            return 0;
        }

        $gameMoves = $game->getGameMoves()->getValues();

        if ($ply < 0 || $ply > \count($gameMoves)) {
            throw new \OutOfRangeException(\sprintf('Ply %d is out of range (game has %d moves).', $ply, \count($gameMoves)));
        }

        /** @var GameMove $gameMove */
        $gameMove = $gameMoves[$ply - 1];
        $move = $gameMove->getMove();
        $cached = $move->getEvaluation();

        if (null !== $cached) {
            return $cached;
        }

        $movesData = new MovesData();

        foreach (\array_slice($gameMoves, 0, $ply) as $played) {
            $movesData->addMove($played->getMove()->getMoveData());
        }

        $evaluation = $this->engineApi->evaluateGame($movesData);
        $move->setEvaluation($evaluation);
        $this->entityManager->flush();

        return $evaluation;
    }

    /**
     * Evaluations for every ply 0..N (index = ply), computing the missing ones.
     *
     * @return list<int>
     */
    public function evaluateAll(Game $game): array
    {
        $count = $game->getGameMoves()->count();
        $evaluations = [];

        for ($ply = 0; $ply <= $count; ++$ply) {
            $evaluations[] = $this->evaluatePly($game, $ply);
        }

        return $evaluations;
    }

    /**
     * Stored evaluations only, no engine call (index = ply, null = unknown).
     * Ply 0 is always 0.
     *
     * @return list<int|null>
     */
    public function storedEvaluations(Game $game): array
    {
        $evaluations = [0];

        foreach ($game->getGameMoves() as $gameMove) {
            $evaluations[] = $gameMove->getMove()->getEvaluation();
        }

        return $evaluations;
    }
}
