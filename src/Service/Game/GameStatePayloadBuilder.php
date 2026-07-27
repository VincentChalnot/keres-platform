<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Model\BoardMovesData;
use App\Model\PieceColor;

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
            'endReason' => 'none',
            'result' => $result,
            'gameOver' => $boardData->gameOver,
            'whiteWins' => $boardData->whiteWins,
            'draw' => $boardData->draw,
            'serverTime' => (int) (new \DateTimeImmutable())->format('Uu'),
        ];
    }

    public function encode(array $payload): string
    {
        return json_encode($payload, self::ENCODE_FLAGS);
    }
}
