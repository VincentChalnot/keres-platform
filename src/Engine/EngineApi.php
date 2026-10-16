<?php

declare(strict_types=1);

namespace App\Engine;

use App\Model\BoardData;
use App\Model\EvaluatedMove;
use App\Model\MoveData;
use App\Model\MovesData;
use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class EngineApi
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $backendApiUrl,
        private string $aiBackendApiUrl,
    ) {
    }

    public function replayMoves(MovesData $movesData): BoardData
    {
        $boardData = $this->callApi('replay-moves', $movesData->toBinary());

        return new BoardData($boardData);
    }

    /**
     * `$level` (1-10, `docs/PROTOCOL.md`'s `/engine-move-game/:level` scale)
     * always has a value by the time this is called (T10:
     * `GameEngine::aiMove()` passes `$game->getAiLevel() ?? 1`) - the
     * default here exists only for a caller that genuinely cannot know the
     * level, of which there are none left after T10.
     */
    public function aiMove(MovesData $movesData, int $level = 1): MoveData
    {
        $endpoint = 'engine-move-game/'.$level;

        try {
            $moveData = $this->callApi($endpoint, $movesData->toBinary(), $this->aiBackendApiUrl);
        } catch (\RuntimeException) {
            $moveData = $this->callApi($endpoint, $movesData->toBinary());
        }

        return new MoveData($moveData);
    }

    /**
     * Level-10 evaluation of the position reached by `$movesData` (`/evaluate-game`,
     * `docs/PROTOCOL.md` in the engine repo): a little-endian int32, White's
     * point of view, engine units (+/-1000 = decided game).
     */
    public function evaluateGame(MovesData $movesData): int
    {
        try {
            $raw = $this->callApi('evaluate-game', $movesData->toBinary(), $this->aiBackendApiUrl);
        } catch (\RuntimeException) {
            $raw = $this->callApi('evaluate-game', $movesData->toBinary());
        }

        if (4 !== \strlen($raw)) {
            throw new \RuntimeException('evaluate-game returned '.\strlen($raw).' bytes, expected 4');
        }

        return self::decodeInt32($raw);
    }

    /**
     * Level-10 evaluation of every legal move of the position reached by
     * `$movesData` (`/evaluate-moves`, `docs/PROTOCOL.md` in the engine repo):
     * `M x 6` bytes, each a `Move` (LE u16, client layout: the stacked and
     * unstacked variants of an unstackable move are separate entries) and an
     * int32 LE score, White's point of view, exact (not a bound). Best move
     * first for the side to move; an empty list when the game is over or no
     * move exists. Same cost as `evaluateGame()` (one search), so it is an
     * AI-backend call like it, with the same fallback to the local backend.
     *
     * @return list<EvaluatedMove>
     */
    public function evaluateMoves(MovesData $movesData): array
    {
        try {
            $raw = $this->callApi('evaluate-moves', $movesData->toBinary(), $this->aiBackendApiUrl);
        } catch (\RuntimeException) {
            $raw = $this->callApi('evaluate-moves', $movesData->toBinary());
        }

        $length = \strlen($raw);

        if (0 !== $length % 6) {
            throw new \RuntimeException('evaluate-moves returned '.$length.' bytes, expected a multiple of 6');
        }

        $moves = [];

        for ($offset = 0; $offset < $length; $offset += 6) {
            $moves[] = new EvaluatedMove(
                new MoveData(substr($raw, $offset, 2)),
                self::decodeInt32(substr($raw, $offset + 2, 4)),
            );
        }

        return $moves;
    }

    /**
     * Why the game reached by `$movesData` is over (`/game-over-reason`,
     * `docs/PROTOCOL.md` in the engine repo): one byte, `0` while the game is
     * still in progress, otherwise the engine's code for the rule that ended
     * it. The codes are the engine's business - PHP stores and relays the
     * value, the client maps it to text. Runs no search, so no AI backend.
     */
    public function gameOverReason(MovesData $movesData): int
    {
        $raw = $this->callApi('game-over-reason', $movesData->toBinary());

        if (1 !== \strlen($raw)) {
            throw new \RuntimeException('game-over-reason returned '.\strlen($raw).' bytes, expected 1');
        }

        return \ord($raw);
    }

    private function callApi(string $endpoint, string $body, ?string $baseUrl = null): string
    {
        $url = rtrim($baseUrl ?? $this->backendApiUrl, '/').'/'.ltrim($endpoint, '/');
        $apiResponse = $this->httpClient->request(
            'POST',
            $url,
            [
                'body' => $body,
                'headers' => [
                    'Content-Type' => 'application/octet-stream',
                ],
            ]
        );

        if (200 !== $apiResponse->getStatusCode()) {
            throw new \RuntimeException("API call to {$endpoint} failed with status code ".$apiResponse->getStatusCode());
        }

        return $apiResponse->getContent();
    }

    /**
     * Unsigned little-endian -> signed 32-bit.
     */
    private static function decodeInt32(string $raw): int
    {
        /** @var int $value */
        $value = unpack('V', $raw)[1];

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }
}
