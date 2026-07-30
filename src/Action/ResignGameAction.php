<?php

declare(strict_types=1);

namespace App\Action;

use App\Engine\GameEngine;
use App\Entity\Game;
use App\Entity\User;
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

#[AsController]
readonly class ResignGameAction
{
    public function __construct(
        private readonly GameRepository $gameRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly GameLifecycleManager $gameLifecycleManager,
        private readonly ClockManager $clockManager,
        private readonly Security $security,
        private readonly GameEngine $gameEngine,
        private readonly GameStatePayloadBuilder $payloadBuilder,
        private readonly GameUpdatePublisher $publisher,
    ) {
    }

    #[Route(
        path: '/play/{uuid}/resign',
        name: 'resign_game',
        methods: ['POST'],
    )]
    public function __(string $uuid): Response
    {
        $game = $this->gameRepository->findByUuid(Uuid::fromString($uuid));

        if (!$game) {
            return new JsonResponse(['error' => 'Game not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->security->isGranted(GameVoter::PARTICIPATE, $game)) {
            return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        if ($game->isGameOver()) {
            return new JsonResponse(
                ['error' => 'game_finished', 'state' => $this->currentPayload($game)],
                Response::HTTP_CONFLICT
            );
        }

        $user = $this->security->getUser();
        $colors = $game->getColorsForUser($user instanceof User ? $user : null);
        // Hot-seat holds both colours for the same user - ambiguous who
        // resigned, so fall back to the creator's colour, matching this
        // action's pre-multiplayer behaviour exactly. Every other mode has
        // exactly one acting colour.
        $resignerColor = 1 === \count($colors) ? $colors[0] : $game->getCreatorColor();

        // Transaction + row lock (matching AbortGameAction/ClockAdjudicator):
        // RatingUpdater::applyForFinishedGame() must run inside the same
        // transaction that writes gameOverAt (06-rating.md sec 9.3), and a
        // bare persist()/flush() here raced a concurrent finaliser (a
        // second resign click, or a flag fall landing at the same instant)
        // into an uncaught OptimisticLockException instead of a clean no-op.
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($game, $resignerColor): void {
            $em->getConnection()->executeStatement("SET LOCAL lock_timeout = '3s'");
            $em->find(Game::class, $game->getId(), LockMode::PESSIMISTIC_WRITE);

            if ($game->isGameOver()) {
                return;
            }

            $this->clockManager->stop($game, $this->clockManager->nowMicros());
            $this->gameLifecycleManager->resign($game, $resignerColor);
            $em->flush();
        });

        // Publish the finished state so the opponent (and the resigner's own
        // tab, which stays on the game page) both see the result via Mercure.
        // Every other finaliser (engine finish, timeout, abort) publishes;
        // resign must too, otherwise the opponent's screen never updates.
        $payload = $this->currentPayload($game);
        $this->publisher->publishGameState($game->getUuid()->toRfc4122(), $this->payloadBuilder->encode($payload));

        return new JsonResponse($payload, Response::HTTP_OK);
    }

    /** @return array<string, mixed> */
    private function currentPayload(Game $game): array
    {
        return $this->payloadBuilder->build($game, $this->gameEngine->getBoardMovesData($game));
    }
}
