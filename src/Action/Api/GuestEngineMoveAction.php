<?php

declare(strict_types=1);

namespace App\Action\Api;

use App\Engine\EngineApi;
use App\Model\MoveData;
use App\Model\MovesData;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /api/engine-move-game` - the AI's reply for a guest (no-account)
 * game, which lives entirely in the visitor's browser and so has no `Game`
 * row for `ProcessAiMoveHandler` to work on. Same binary contract as the
 * engine endpoint (N moves in, one 2-byte move out), relayed through
 * `EngineApi` so it gets the AI backend and its fallback. Rate-limited per
 * client IP: this is the one anonymous route that costs engine CPU.
 */
#[AsController]
readonly class GuestEngineMoveAction
{
    /** Far beyond any real game; bounds the body we relay to the engine. */
    private const int MAX_PLIES = 1000;

    public function __construct(
        private EngineApi $engineApi,
        private RateLimiterFactory $guestEngineMoveLimiter,
    ) {
    }

    #[Route(path: '/api/engine-move-game', name: 'api_guest_engine_move', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->guestEngineMoveLimiter->create($request->getClientIp() ?? 'unknown')->consume(1)->isAccepted()) {
            return new Response('Too many requests', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $body = $request->getContent();
        $length = \strlen($body);

        if (0 !== $length % 2 || $length > 2 * self::MAX_PLIES) {
            return new Response('Invalid move list', Response::HTTP_BAD_REQUEST);
        }

        $movesData = new MovesData();

        foreach (str_split($body, 2) as $chunk) {
            if ('' !== $chunk) {
                $movesData->addMove(new MoveData($chunk));
            }
        }

        try {
            $move = $this->engineApi->aiMove($movesData);
        } catch (\RuntimeException) {
            return new Response('Engine unavailable', Response::HTTP_BAD_GATEWAY);
        }

        return new Response($move->data, Response::HTTP_OK, ['Content-Type' => 'application/octet-stream']);
    }
}
