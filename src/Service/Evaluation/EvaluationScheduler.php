<?php

declare(strict_types=1);

namespace App\Service\Evaluation;

use App\Entity\Game;
use App\Entity\GameMove;
use App\Entity\Move;
use App\Message\EvaluateMoveMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The request-side half of the evaluation pipeline: never calls the engine,
 * only reads what is stored and queues `EvaluateMoveMessage` for the rest.
 * The worker (`EvaluateMoveHandler`) computes, stores and pushes the result
 * to the browsers over Mercure.
 */
readonly class EvaluationScheduler
{
    public function __construct(
        private MoveEvaluator $moveEvaluator,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Queues the plies of `$game` that have no stored evaluation, the latest
     * first: it is the position the evaluation bar shows on arrival.
     *
     * @return list<int|null> the evaluations stored so far (index = ply, null = queued)
     */
    public function scheduleMissing(Game $game): array
    {
        $evaluations = $this->moveEvaluator->storedEvaluations($game);

        foreach (array_reverse($evaluations, true) as $ply => $evaluation) {
            if (null === $evaluation) {
                $this->schedule($game, $ply);
            }
        }

        return $evaluations;
    }

    /**
     * Queues the evaluation of one move edge on its own, through the first game
     * that played it (the admin opening explorer only knows the edge).
     *
     * @return bool false when no game contains the edge (e.g. its game was undone)
     */
    public function scheduleMove(Move $move): bool
    {
        /** @var GameMove|null $gameMove */
        $gameMove = $this->entityManager->getRepository(GameMove::class)->findOneBy(['move' => $move], ['id' => 'ASC']);

        if (null === $gameMove) {
            return false;
        }

        $game = $gameMove->getGame();
        $ply = 0;

        foreach ($game->getGameMoves() as $candidate) {
            ++$ply;

            if ($candidate->getMove() === $move) {
                break;
            }
        }

        $this->schedule($game, $ply);

        return true;
    }

    private function schedule(Game $game, int $ply): void
    {
        $this->messageBus->dispatch(new EvaluateMoveMessage($game->getUuid()->toRfc4122(), $ply));
    }
}
