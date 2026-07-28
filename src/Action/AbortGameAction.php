<?php

declare(strict_types=1);

namespace App\Action;

use App\Engine\GameEngine;
use App\Entity\Game;
use App\Repository\GameRepository;
use App\Security\Voter\GameVoter;
use App\Service\Game\ClockManager;
use App\Service\Game\GameLifecycleManager;
use App\Service\Game\GameStatePayloadBuilder;
use App\Service\Game\GameUpdatePublisher;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * 03-time-control.md sec 7.2: needs no consent, refused once
 * `Game::isAbortable()` no longer holds - the exact negation of invariant
 * 3's rated-ply floor, so there is nothing to lose by it.
 */
#[AsController]
readonly class AbortGameAction
{
    public function __construct(
        private GameRepository $gameRepository,
        private Security $security,
        private EntityManagerInterface $entityManager,
        private ClockManager $clockManager,
        private GameLifecycleManager $gameLifecycleManager,
        private GameEngine $gameEngine,
        private GameStatePayloadBuilder $payloadBuilder,
        private GameUpdatePublisher $publisher,
    ) {
    }

    #[Route(
        path: '/play/{uuid}/abort',
        name: 'abort_game',
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

        if (!$game->isAbortable()) {
            $payload = $this->payloadBuilder->build($game, $this->gameEngine->getBoardMovesData($game));

            return new JsonResponse(
                ['error' => 'abort_not_allowed', 'state' => $payload],
                Response::HTTP_CONFLICT
            );
        }

        $aborted = $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $em) use ($game): bool {
                $em->getConnection()->executeStatement("SET LOCAL lock_timeout = '3s'");
                $em->find(Game::class, $game->getId(), LockMode::PESSIMISTIC_WRITE);

                if (!$game->isAbortable()) {
                    return false;
                }

                $this->clockManager->stop($game, $this->clockManager->nowMicros());
                $this->gameLifecycleManager->finaliseAbort($game);
                $em->flush();

                return true;
            }
        );

        $payload = $this->payloadBuilder->build($game, $this->gameEngine->getBoardMovesData($game));

        if (!$aborted) {
            return new JsonResponse(
                ['error' => 'abort_not_allowed', 'state' => $payload],
                Response::HTTP_CONFLICT
            );
        }

        $this->publisher->publishGameState($game->getUuid()->toRfc4122(), $this->payloadBuilder->encode($payload));

        return new JsonResponse($payload, Response::HTTP_OK);
    }
}
