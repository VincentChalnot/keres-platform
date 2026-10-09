<?php

declare(strict_types=1);

namespace App\Action\Api;

use App\Engine\BoardTreeManager;
use App\Model\MoveData;
use App\Model\MovesData;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /api/guest-moves` - a guest (no-account) game has no `Game` row, but
 * its moves still feed the shared analytics tree. The browser posts the whole
 * move list after each ply (binary, 2 bytes per move, same wire format as the
 * engine relays); the last move is recorded as a `Move` edge between two
 * `BoardPosition` rows, both derived by replaying the list through the engine
 * (`BoardTreeManager::recordLastMove()`), so a forged or illegal list records
 * nothing. No `Game`/`GameMove` row, no evaluation job. Rate-limited per IP.
 */
#[AsController]
readonly class GuestMoveRecordAction
{
    /** Far beyond any real game; bounds the body we replay. */
    private const int MAX_PLIES = 1000;

    public function __construct(
        private BoardTreeManager $boardTreeManager,
        private RateLimiterFactory $guestMoveRecordLimiter,
    ) {
    }

    #[Route(path: '/api/guest-moves', name: 'api_guest_move_record', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->guestMoveRecordLimiter->create($request->getClientIp() ?? 'unknown')->consume(1)->isAccepted()) {
            return new Response('Too many requests', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $body = $request->getContent();
        $length = \strlen($body);

        if (0 === $length || 0 !== $length % 2 || $length > 2 * self::MAX_PLIES) {
            return new Response('Invalid move list', Response::HTTP_BAD_REQUEST);
        }

        $line = new MovesData();

        foreach (str_split($body, 2) as $chunk) {
            $line->addMove(new MoveData($chunk));
        }

        try {
            $this->boardTreeManager->recordLastMove($line);
        } catch (\RuntimeException) {
            return new Response('Illegal move list or engine unavailable', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
