<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Model\GameEndReason;
use App\Model\PieceColor;

/**
 * The only class permitted to finalise a game (03-time-control.md sec 5.6,
 * 01-domain-model.md sec 4.3) - every path funnels through `Game::finish()`
 * from here, never directly, so a rating/notification hook added later has
 * exactly one call site to attach to. Callers own clock finalisation
 * (`ClockManager::stop()`) themselves, immediately before calling in here -
 * see 03-time-control.md sec 4.1 steps 13/16 and sec 5.1.
 */
final readonly class GameLifecycleManager
{
    /** The engine's own verdict (board.gameOver). */
    public function finaliseEngineResult(Game $game, bool $whiteWins, bool $draw): void
    {
        $winner = $draw ? null : ($whiteWins ? PieceColor::WHITE : PieceColor::BLACK);
        $game->finish(GameEndReason::ENGINE, $winner);
    }

    /** Never a draw (06-rating.md sec 6.2). */
    public function resign(Game $game, PieceColor $resigner): void
    {
        $game->finish(GameEndReason::RESIGNATION, $resigner->opposite());
    }

    /** A flag falling past ply 1 - real result, rated if invariant 3 otherwise holds. */
    public function finaliseTimeout(Game $game, PieceColor $loser): void
    {
        $game->finish(GameEndReason::TIMEOUT, $loser->opposite());
    }

    /**
     * The clamp expiring inside the first two plies (03-time-control.md
     * sec 7.1) or an explicit `POST /play/{uuid}/abort` while
     * `Game::isAbortable()` holds (sec 7.2). No result, never rated.
     */
    public function finaliseAbort(Game $game): void
    {
        $game->finish(GameEndReason::ABORTED, null);
    }
}
