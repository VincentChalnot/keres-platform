<?php

declare(strict_types=1);

namespace App\Service\Coach;

use App\Engine\BoardTreeManager;
use App\Engine\EngineApi;
use App\Model\MoveData;
use App\Model\MovesData;

/**
 * The coach's hint: one level-10 search of the position (`EngineApi::evaluateMoves()`, one AI-backend call, which is what
 * the caller's `user_ai` / `guest_ai` token pays for) whose best-first list gives the move (the first entry), and whose
 * every entry - the moves nobody plays included - is stored on the shared tree (`BoardTreeManager::recordEvaluatedMoves()`).
 * The caller decides who may ask and when; nothing here knows a game rule.
 */
final readonly class HintProvider
{
    public function __construct(
        private EngineApi $engineApi,
        private BoardTreeManager $boardTreeManager,
    ) {
    }

    /**
     * @throws \RuntimeException when the engine fails, or the game is over / the line is illegal (no move to hint)
     */
    public function hint(MovesData $history): MoveData
    {
        $evaluated = $this->engineApi->evaluateMoves($history);

        if ([] === $evaluated) {
            throw new \RuntimeException('The engine has no move to suggest here.');
        }

        $this->boardTreeManager->recordEvaluatedMoves($history, $evaluated);

        return $evaluated[0]->move;
    }
}
