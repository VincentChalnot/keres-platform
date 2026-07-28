<?php

declare(strict_types=1);

namespace App\Action;

use App\Engine\GameEngine;
use App\Entity\Game;
use App\Entity\User;
use App\Exception\GameAlreadyFinishedException;
use App\Exception\MoveFlaggedException;
use App\Exception\StalePositionException;
use App\Message\CheckClockExpiryMessage;
use App\Message\ProcessAiMoveMessage;
use App\Model\MoveData;
use App\Model\MultiplayerLimits;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Model\TimeControlKind;
use App\Repository\GameRepository;
use App\Security\Voter\GameVoter;
use App\Service\Game\ClockAdjudicator;
use App\Service\Game\ClockManager;
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
use Symfony\Component\Messenger\Stamp\DelayStamp;
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
        private ClockAdjudicator $clockAdjudicator,
        private ClockManager $clockManager,
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
        // anchor per `03-time-control.md` sec. 2. Threaded into GameEngine so
        // engine and platform latency between here and the row lock never
        // burns the mover's clock.
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

        // The safety net (path b, 03-time-control.md sec 5.2): a flag that
        // fell before this request arrived is resolved here, for free, before
        // any move is even attempted.
        $this->clockAdjudicator->adjudicate($game);

        if ($game->isGameOver()) {
            return $this->finishedResponse($game, 'game_finished');
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
        } catch (MoveFlaggedException) {
            return $this->finishedResponse($game, 'flagged');
        } catch (GameAlreadyFinishedException) {
            return $this->finishedResponse($game, 'game_finished');
        } catch (StalePositionException) {
            return new JsonResponse(
                ['error' => 'not_your_turn', 'state' => $this->currentPayload($game)],
                Response::HTTP_CONFLICT
            );
        } catch (OptimisticLockException|RetryableException) {
            return new JsonResponse(
                ['error' => 'concurrent_move'],
                Response::HTTP_CONFLICT
            );
        }

        $payload = $this->payloadBuilder->build($game, $boardMovesData);
        $json = $this->payloadBuilder->encode($payload);

        $this->publisher->publishGameState($game->getUuid()->toRfc4122(), $json);

        if (!$game->isGameOver()) {
            $deadline = $game->getMoveDeadlineAt();

            if (null !== $deadline && TimeControlKind::UNLIMITED !== $game->getTimeControl()->getKind()) {
                $this->dispatchClockExpiryCheck($game, $deadline);
            }

            if (OpponentType::AI === $game->getOpponentType()) {
                $this->messageBus->dispatch(
                    new ProcessAiMoveMessage(
                        $uuid,
                        $game->getGameMoves()->count(),
                    )
                );
            }
        }

        return new JsonResponse(
            $payload,
            Response::HTTP_OK,
            [
                AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER => true,
            ]
        );
    }

    /** Even an UNLIMITED game carries a deadline for its first two plies (the abort clamp). */
    private function dispatchClockExpiryCheck(Game $game, \DateTimeImmutable $deadline): void
    {
        $deadlineMicros = (int) $deadline->format('Uu');
        $graceMicros = (MultiplayerLimits::CLOCK_LAG_COMPENSATION_MS + MultiplayerLimits::CLOCK_EXPIRY_GRACE_MS) * 1000;
        $fireAtMicros = $deadlineMicros + $graceMicros;
        $delayMs = max(0, intdiv($fireAtMicros - $this->clockManager->nowMicros(), 1000));

        $this->messageBus->dispatch(
            new CheckClockExpiryMessage($game->getUuid()->toRfc4122(), $game->getGameMoves()->count(), $deadlineMicros),
            [new DelayStamp($delayMs)]
        );
    }

    private function finishedResponse(Game $game, string $errorCode): JsonResponse
    {
        return new JsonResponse(
            ['error' => $errorCode, 'state' => $this->currentPayload($game)],
            Response::HTTP_CONFLICT
        );
    }

    /** @return array<string, mixed> */
    private function currentPayload(Game $game): array
    {
        return $this->payloadBuilder->build($game, $this->gameEngine->getBoardMovesData($game));
    }
}
