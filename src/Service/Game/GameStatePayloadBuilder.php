<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
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
            'result' => $result,
            'gameOver' => $game->isGameOver(),
            'whiteWins' => $game->isWhiteWins(),
            'draw' => $game->isDraw(),
            'clock' => $this->buildClock($game),
            'serverTime' => (int) (new \DateTimeImmutable())->format('Uu'),
        ];
    }

    public function encode(array $payload): string
    {
        return json_encode($payload, self::ENCODE_FLAGS);
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

    private function microsOrNull(?\DateTimeImmutable $dateTime): ?int
    {
        return $dateTime?->format('Uu') ? (int) $dateTime->format('Uu') : null;
    }
}
