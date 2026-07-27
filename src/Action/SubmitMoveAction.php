<?php

declare(strict_types=1);

namespace App\Action;

use App\Engine\GameEngine;
use App\Entity\User;
use App\Message\ProcessAiMoveMessage;
use App\Model\MoveData;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Repository\GameRepository;
use App\Security\Voter\GameVoter;
use App\Service\Game\GameStatePayloadBuilder;
use App\Service\Game\GameUpdatePublisher;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
readonly class SubmitMoveAction
{
    public function __construct(
        private GameRepository $gameRepository,
        private MessageBusInterface $messageBus,
        private GameEngine $gameEngine,
        private Security $security,
        private GameStatePayloadBuilder $payloadBuilder,
        private GameUpdatePublisher $publisher,
    ) {
    }

    #[Route(
        path: '/play/{uuid}/move',
        name: 'submit_move',
        methods: ['POST'],
    )]
    public function __(string $uuid, Request $request): Response
    {
        // Captured first, before any lookup/voter/validation: the clock-charging
        // anchor per `03-time-control.md` sec. 2. Unused until Phase 2 wires
        // ClockManager, but must already sit at the true controller entry so
        // Phase 2 doesn't shift the measured window.
        $receivedAtMicros = (int) (microtime(true) * 1_000_000);

        $game = $this->gameRepository->findByUuid(Uuid::fromString($uuid));

        if (!$game) {
            return new JsonResponse(
                ['error' => 'Game not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        if (!$this->security->isGranted(GameVoter::PARTICIPATE, $game)) {
            return new JsonResponse(
                ['error' => 'Access denied'],
                Response::HTTP_FORBIDDEN
            );
        }

        if ($game->isGameOver()) {
            return new JsonResponse(
                ['error' => 'Game is already over'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $user = $this->security->getUser();
        $sideToMove = $game->isWhiteTurn() ? PieceColor::WHITE : PieceColor::BLACK;
        $isPlayerTurn = \in_array(
            $sideToMove,
            $game->getColorsForUser($user instanceof User ? $user : null),
            true
        );

        if (!$isPlayerTurn) {
            return new JsonResponse(
                ['error' => 'Not your turn'],
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            $moveData = new MoveData($request->getContent());
        } catch (\Exception $e) {
            return new JsonResponse(
                ['error' => 'Invalid move data: '.$e->getMessage()],
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            $boardMovesData = $this->gameEngine->applyMove($game, $moveData, $receivedAtMicros);
        } catch (OptimisticLockException|RetryableException) {
            return new JsonResponse(
                ['error' => 'concurrent_move'],
                Response::HTTP_CONFLICT
            );
        }

        $payload = $this->payloadBuilder->build($game, $boardMovesData);
        $json = $this->payloadBuilder->encode($payload);

        $this->publisher->publishGameState($game->getUuid()->toRfc4122(), $json);

        if (!$game->isGameOver() && OpponentType::AI === $game->getOpponentType()) {
            $this->messageBus->dispatch(
                new ProcessAiMoveMessage(
                    $uuid,
                    $game->getGameMoves()->count(),
                )
            );
        }

        return new JsonResponse(
            $payload,
            Response::HTTP_OK,
            [
                AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER => true,
            ]
        );
    }
}
