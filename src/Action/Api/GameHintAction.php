<?php

declare(strict_types=1);

namespace App\Action\Api;

use App\Entity\User;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Repository\GameRepository;
use App\Security\Voter\GameVoter;
use App\Service\Coach\HintProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /api/games/{uuid}/hint` - the coach's hint for a signed-in player: the engine's best move for the position, as
 * `{"move": <u16 of the wire format>}` (opaque to PHP, the client decodes it and draws the arrow). Only a casual game
 * against the bot (`OpponentType::AI`), in progress, on the participant's own turn: anything else is a 403 (never a
 * rated game, never multiplayer, never a spectator). Consumes the user's `user_ai` budget once per call (429
 * `ai_rate_limited`), marks the game assisted (`Game::$assisted`: it no longer counts for the AI ladder) and stores every
 * move the search evaluated on the shared tree (`HintProvider`).
 */
#[AsController]
readonly class GameHintAction
{
    public function __construct(
        private GameRepository $gameRepository,
        private Security $security,
        private RateLimiterFactory $userAiLimiter,
        private HintProvider $hintProvider,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(
        path: '/api/games/{uuid}/hint',
        name: 'api_game_hint',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function __invoke(string $uuid): JsonResponse
    {
        $user = $this->security->getUser();
        $game = $this->gameRepository->findByUuid(Uuid::fromString($uuid));

        if (!$user instanceof User || !$game || !$this->security->isGranted(GameVoter::PARTICIPATE, $game)) {
            return ApiResponse::error(ApiErrorCode::NOT_FOUND, 'Game not found.');
        }

        $color = $game->isWhiteTurn() ? PieceColor::WHITE : PieceColor::BLACK;

        if (OpponentType::AI !== $game->getOpponentType() || $game->isRated() || $game->isGameOver() || !\in_array($color, $game->getColorsForUser($user), true)) {
            return ApiResponse::error(ApiErrorCode::FORBIDDEN, 'A hint is only available on your turn in a casual game against the bot.');
        }

        if (!$this->userAiLimiter->create($user->getId()->toRfc4122())->consume(1)->isAccepted()) {
            return ApiResponse::error(ApiErrorCode::AI_RATE_LIMITED, 'Too many requests to the AI.');
        }

        try {
            $move = $this->hintProvider->hint($game->getMovesData());
        } catch (\RuntimeException) {
            return ApiResponse::error(ApiErrorCode::INTERNAL_ERROR, 'The engine could not suggest a move.');
        }

        $game->markAssisted();
        $this->entityManager->flush();

        /** @var array{1: int} $value */
        $value = unpack('v', $move->data);

        return ApiResponse::ok(['move' => $value[1]]);
    }
}
