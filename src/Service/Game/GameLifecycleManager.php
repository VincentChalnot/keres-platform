<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Model\GameEndReason;
use App\Model\PieceColor;
use App\Service\Rating\RatingUpdater;

/**
 * The only class permitted to finalise a game (03-time-control.md sec 5.6,
 * 01-domain-model.md sec 4.3) - every path funnels through `Game::finish()`
 * from here, never directly, which is why the rating hook (06-rating.md
 * sec 9.3) attaches here and nowhere else: one call site, so no finaliser
 * can forget it. Callers own clock finalisation (`ClockManager::stop()`)
 * themselves, immediately before calling in here - see
 * 03-time-control.md sec 4.1 steps 13/16 and sec 5.1.
 */
final readonly class GameLifecycleManager
{
    public function __construct(
        private RatingUpdater $ratingUpdater,
    ) {
    }

    /** The engine's own verdict (board.gameOver). */
    public function finaliseEngineResult(Game $game, bool $whiteWins, bool $draw): void
    {
        $winner = $draw ? null : ($whiteWins ? PieceColor::WHITE : PieceColor::BLACK);
        $game->finish(GameEndReason::ENGINE, $winner);
        $this->ratingUpdater->applyForFinishedGame($game);
    }

    /** Never a draw (06-rating.md sec 6.2). */
    public function resign(Game $game, PieceColor $resigner): void
    {
        $game->finish(GameEndReason::RESIGNATION, $resigner->opposite());
        $this->ratingUpdater->applyForFinishedGame($game);
    }

    /** A flag falling past ply 1 - real result, rated if invariant 3 otherwise holds. */
    public function finaliseTimeout(Game $game, PieceColor $loser): void
    {
        $game->finish(GameEndReason::TIMEOUT, $loser->opposite());
        $this->ratingUpdater->applyForFinishedGame($game);
    }

    /**
     * The clamp expiring inside the first two plies (03-time-control.md
     * sec 7.1) or an explicit `POST /play/{uuid}/abort` while
     * `Game::isAbortable()` holds (sec 7.2). No result, never rated - the
     * `applyForFinishedGame` call still runs for uniformity (single call
     * site, sec 9.3 step 3) but is a guaranteed no-op on `isRatedOutcome()`.
     */
    public function finaliseAbort(Game $game): void
    {
        $game->finish(GameEndReason::ABORTED, null);
        $this->ratingUpdater->applyForFinishedGame($game);
    }
}
