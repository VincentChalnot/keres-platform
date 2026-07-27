<?php

declare(strict_types=1);

namespace App\Engine;

use App\Entity\Game;
use App\Model\BoardMovesData;
use App\Model\MoveData;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\LockMode;

readonly class GameEngine
{
    public function __construct(
        private BoardTreeManager $boardTreeManager,
        private EntityManagerInterface $entityManager,
        private EngineApi $engineApi,
    ) {
    }

    /**
     * Returns a BoardMovesData for the current game state (board after all moves played).
     */
    public function getBoardMovesData(Game $game): BoardMovesData
    {
        $movesData = $game->getMovesData();
        $boardData = $this->engineApi->replayMoves($movesData);

        return new BoardMovesData($boardData, $movesData);
    }

    public function applyMove(Game $game, MoveData $moveData): BoardMovesData
    {
        // Engine round trip: no transaction, no lock.
        $movesData = $game->getMovesData();
        $movesData->addMove($moveData);
        $expectedVersion = $game->getVersion();
        $boardData = $this->engineApi->replayMoves($movesData);
        $boardMovesData = new BoardMovesData($boardData, $movesData);

        return $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $em) use ($game, $boardMovesData, $expectedVersion, $boardData): BoardMovesData {
                $em->getConnection()->executeStatement("SET LOCAL lock_timeout = '3s'");

                $em->find(Game::class, $game->getId(), LockMode::PESSIMISTIC_WRITE);

                if (null !== $game->getGameOverAt()) {
                    throw new \RuntimeException('Game is over.');
                }

                if ($game->getVersion() !== $expectedVersion) {
                    throw new \RuntimeException('A concurrent move was applied.');
                }

                $newMove = $this->boardTreeManager->getGameMove($game, $boardMovesData);
                $em->persist($newMove);

                if ($boardData->gameOver) {
                    $game->setGameOverAt(new \DateTimeImmutable());
                    $game->setWhiteWins($boardData->whiteWins);
                    $game->setDraw($boardData->draw);
                }

                $em->flush();

                // Without clock fields (Phase 2), non-terminal moves do not dirty
                // Game, so Doctrine emits no UPDATE and the version does not bump.
                // Bump it directly and refresh so getVersion() returns the correct
                // value. This transitional block is deleted when clocks write Game
                // on every move.
                if (!$boardData->gameOver) {
                    $em->getConnection()->executeStatement(
                        'UPDATE game SET version = version + 1 WHERE id = :id',
                        ['id' => $game->getId()]
                    );
                    $em->refresh($game);
                }

                return $boardMovesData;
            }
        );
    }

    public function aiMove(Game $game): BoardMovesData
    {
        if ($game->isGameOver()) {
            // Game is already over, nothing to do
            throw new \RuntimeException('Game is over.');
        }

        // Get current board state
        $movesData = $game->getMovesData();

        // Get AI move
        $aiMoveData = $this->engineApi->aiMove($movesData);

        // Apply AI move
        return $this->applyMove($game, $aiMoveData);
    }
}
