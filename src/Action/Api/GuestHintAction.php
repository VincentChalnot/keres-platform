<?php

declare(strict_types=1);

namespace App\Action\Api;

use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Model\MoveData;
use App\Model\MovesData;
use App\Service\Coach\HintProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /api/hint` - the coach's hint for a guest (no `Game` row) game against the bot: the whole move list of the game as
 * the body (`N x 2` bytes, like `/api/engine-move-game`), the engine's best move back as `{"move": <u16>}`. Consumes the
 * client IP's `guest_ai` budget once per call (429 `ai_rate_limited`). The line is replayed by the engine, so an illegal
 * one answers an error and stores nothing; as for any guest route, "it is the player's turn" is not something the server
 * can know (the browser holds the game). Every evaluated move goes to the shared tree (`HintProvider`).
 */
#[AsController]
readonly class GuestHintAction
{
    /** Far beyond any real game; bounds the body we relay to the engine. */
    private const int MAX_PLIES = 1000;

    public function __construct(
        private RateLimiterFactory $guestAiLimiter,
        private HintProvider $hintProvider,
    ) {
    }

    #[Route(path: '/api/hint', name: 'api_guest_hint', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $length = \strlen($body);

        if (0 !== $length % 2 || $length > 2 * self::MAX_PLIES) {
            return ApiResponse::error(ApiErrorCode::VALIDATION_FAILED, 'Invalid move list.');
        }

        if (!$this->guestAiLimiter->create($request->getClientIp() ?? 'unknown')->consume(1)->isAccepted()) {
            return ApiResponse::error(ApiErrorCode::AI_RATE_LIMITED, 'Too many requests to the AI.');
        }

        $history = new MovesData();

        foreach ('' === $body ? [] : str_split($body, 2) as $chunk) {
            $history->addMove(new MoveData($chunk));
        }

        try {
            $move = $this->hintProvider->hint($history);
        } catch (\RuntimeException) {
            return ApiResponse::error(ApiErrorCode::INTERNAL_ERROR, 'The engine could not suggest a move.');
        }

        /** @var array{1: int} $value */
        $value = unpack('v', $move->data);

        return ApiResponse::ok(['move' => $value[1]]);
    }
}
