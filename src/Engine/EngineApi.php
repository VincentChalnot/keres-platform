<?php

declare(strict_types=1);

namespace App\Engine;

use App\Model\BoardData;
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

        /** @var int $value */
        $value = unpack('V', $raw)[1];

        // Unsigned little-endian -> signed 32-bit.
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
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
}
