<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\EvaluateMoveMessage;
use App\Repository\GameRepository;
use App\Service\Evaluation\MoveEvaluator;
use App\Service\Game\GameUpdatePublisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Runs the (slow) engine search off the request path, stores the verdict on
 * the move edge and, when the game may show it, pushes it to the browsers
 * watching the game.
 */
#[AsMessageHandler]
readonly class EvaluateMoveHandler
{
    public function __construct(
        private GameRepository $gameRepository,
        private MoveEvaluator $moveEvaluator,
        private GameUpdatePublisher $publisher,
    ) {
    }

    public function __invoke(EvaluateMoveMessage $message): void
    {
        $game = $this->gameRepository->findAnyByUuid(Uuid::fromString($message->gameUuid));

        // Deleted or undone in the meantime: nothing to evaluate any more.
        if (null === $game || $message->ply > $game->getGameMoves()->count()) {
            return;
        }

        $evaluation = $this->moveEvaluator->evaluatePly($game, $message->ply);

        // A rated game in progress keeps its evaluations to itself (they are
        // stored all the same, and shipped with the final state once it ends).
        if ($game->canExposeEvaluation()) {
            $this->publisher->publishEvaluation($message->gameUuid, $message->ply, $evaluation);
        }
    }
}
