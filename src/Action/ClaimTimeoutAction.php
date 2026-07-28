<?php

declare(strict_types=1);

namespace App\Action;

use App\Engine\GameEngine;
use App\Repository\GameRepository;
use App\Security\Voter\GameVoter;
use App\Service\Game\ClockAdjudicator;
use App\Service\Game\GameStatePayloadBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The player-facing escape hatch for path (c) of flag adjudication
 * (03-time-control.md sec 5.2). `false` from the adjudicator means the
 * deadline genuinely has not passed - the client resyncs its countdown from
 * `state` instead of the request just failing silently.
 */
#[AsController]
readonly class ClaimTimeoutAction
{
    public function __construct(
        private GameRepository $gameRepository,
        private Security $security,
        private ClockAdjudicator $clockAdjudicator,
        private GameStatePayloadBuilder $payloadBuilder,
        private GameEngine $gameEngine,
    ) {
    }

    #[Route(
        path: '/play/{uuid}/claim-timeout',
        name: 'claim_timeout',
        methods: ['POST'],
    )]
    public function __invoke(string $uuid): JsonResponse
    {
        $game = $this->gameRepository->findByUuid(Uuid::fromString($uuid));

        if (!$game) {
            return new JsonResponse(['error' => 'Game not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->security->isGranted(GameVoter::PARTICIPATE, $game)) {
            return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $this->clockAdjudicator->adjudicate($game);

        $payload = $this->payloadBuilder->build($game, $this->gameEngine->getBoardMovesData($game));

        if ($game->isGameOver()) {
            return new JsonResponse($payload, Response::HTTP_OK);
        }

        return new JsonResponse(
            ['error' => 'clock_not_expired', 'state' => $payload],
            Response::HTTP_CONFLICT
        );
    }
}
