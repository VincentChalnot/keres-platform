<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Entity\GamePlayer;
use App\Model\BoardMovesData;
use App\Model\PieceColor;
use App\Model\TimeControlKind;

final readonly class GameStatePayloadBuilder
{
    private const ENCODE_FLAGS = \JSON_THROW_ON_ERROR
        | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE;

    /**
     * @return array<string, mixed>
     */
    public function build(Game $game, BoardMovesData $boardMovesData): array
    {
        $boardData = $boardMovesData->boardData;

        $status = match (true) {
            null !== $game->getGameOverAt() => 'finished',
            0 === $game->getGameMoves()->count() => 'created',
            default => 'ongoing',
        };

        // Read the result from Game, not BoardData: BoardData reflects only
        // the engine's own verdict for *this* request, which is false/absent
        // on every non-engine finish (timeout, resignation, abort). Game's
        // own fields are the single source of truth once `finish()` has run.
        $result = match (true) {
            null === $game->getGameOverAt() => null,
            $game->isDraw() => 'draw',
            $game->isWhiteWins() => 'white',
            default => 'black',
        };

        return [
            'type' => 'game.state',
            'gameUuid' => $game->getUuid()->toRfc4122(),
            'seq' => $game->getVersion(),
            'board' => base64_encode($boardData->data),
            'moves' => base64_encode($boardMovesData->movesData->toBinary()),
            'status' => $status,
            'endReason' => strtolower($game->getEndReason()->name),
            'engineEndCode' => $game->getEngineEndCode(),
            'result' => $result,
            'gameOver' => $game->isGameOver(),
            'whiteWins' => $game->isWhiteWins(),
            'draw' => $game->isDraw(),
            'clock' => $this->buildClock($game),
            'rating' => $this->buildRating($game),
            'aiLevel' => $game->getAiLevel(),
            'liveEvaluation' => $game->isLiveEvaluationEnabled(),
            'evaluations' => $this->buildEvaluations($game),
            'serverTime' => (int) (new \DateTimeImmutable())->format('Uu'),
        ];
    }

    public function encode(array $payload): string
    {
        return json_encode($payload, self::ENCODE_FLAGS);
    }

    /**
     * Stored engine evaluations, index = ply (0 = start position), null =
     * not computed yet (the client asks the evaluation API for those). Null
     * as a whole while the game must not leak them: a rated game in
     * progress, or an unrated one that did not opt in. This payload also
     * goes out over Mercure to spectators, hence the gate here.
     *
     * @return list<int|null>|null
     */
    private function buildEvaluations(Game $game): ?array
    {
        if (!$game->canExposeEvaluation()) {
            return null;
        }

        $evaluations = [0];

        foreach ($game->getGameMoves() as $gameMove) {
            $evaluations[] = $gameMove->getMove()->getEvaluation();
        }

        return $evaluations;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildClock(Game $game): array
    {
        $timeControl = $game->getTimeControl();
        $running = null;

        if (null === $game->getGameOverAt() && TimeControlKind::UNLIMITED !== $timeControl->getKind()) {
            $running = $game->isWhiteTurn() ? 'white' : 'black';
        }

        return [
            'kind' => strtolower($timeControl->getKind()->name),
            'whiteMs' => $game->getPlayer(PieceColor::WHITE)->getClockMsRemaining(),
            'blackMs' => $game->getPlayer(PieceColor::BLACK)->getClockMsRemaining(),
            'running' => $running,
            'turnStartedAt' => $this->microsOrNull($game->getClockTurnStartedAt()),
            'deadlineAt' => $this->microsOrNull($game->getMoveDeadlineAt()),
        ];
    }

    /**
     * `null` unless the game finished rated (02-realtime.md sec 4.1) - the
     * only source of truth is `Game::isRatedOutcome()`, called after
     * `finish()` has already run so `rated`/`endReason`/ply counts are
     * final. `GamePlayer.ratingBefore`/`.ratingAfter` are write-once,
     * populated by `RatingUpdater::applyForFinishedGame()` in the same
     * transaction that called `finish()`.
     *
     * @return array<string, array<string, int>>|null
     */
    private function buildRating(Game $game): ?array
    {
        if (!$game->isRatedOutcome()) {
            return null;
        }

        return [
            'white' => $this->buildRatingSide($game->getPlayer(PieceColor::WHITE)),
            'black' => $this->buildRatingSide($game->getPlayer(PieceColor::BLACK)),
        ];
    }

    /** @return array<string, int> */
    private function buildRatingSide(GamePlayer $player): array
    {
        $before = $player->getRatingBefore();
        $after = $player->getRatingAfter();

        \assert(null !== $before && null !== $after, 'RatingUpdater::applyForFinishedGame() must have run for a rated outcome.');

        return ['before' => $before, 'after' => $after, 'delta' => $after - $before];
    }

    private function microsOrNull(?\DateTimeImmutable $dateTime): ?int
    {
        return $dateTime?->format('Uu') ? (int) $dateTime->format('Uu') : null;
    }
}
