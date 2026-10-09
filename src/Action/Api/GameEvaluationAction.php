<?php

declare(strict_types=1);

namespace App\Action\Api;

use App\Entity\Game;
use App\Entity\User;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Repository\GameRepository;
use App\Security\Voter\GameVoter;
use App\Service\Evaluation\EvaluationScheduler;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /api/games/{uuid}/evaluation` - asks for the engine's evaluation of
 * every position of the game. Never waits for the engine: answers `202` at
 * once with what is stored so far (`evaluations`, index = ply, `null` = not
 * computed yet) after queueing the missing plies; the worker computes them
 * and pushes each one over Mercure (`evaluation` event on `game/{uuid}`).
 * Idempotent, so a client can call it freely (page load, game over).
 *
 * Reserved for unrated games. Asking it about a RATED game that is still in
 * progress can only mean someone is trying to get engine help during a
 * ranked game: it is answered with a 403 *and* reported to Sentry as a
 * fatal event (plus a `critical` log line), with the user and game, so the
 * cheating attempt gets noticed. A finished rated game is fine - that is
 * the replay.
 */
#[AsController]
readonly class GameEvaluationAction
{
    public function __construct(
        private GameRepository $gameRepository,
        private Security $security,
        private EvaluationScheduler $evaluationScheduler,
        private LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/api/games/{uuid}/evaluation',
        name: 'api_game_evaluation',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function __invoke(string $uuid): JsonResponse
    {
        $game = $this->gameRepository->findByUuid(Uuid::fromString($uuid));

        if (!$game || !$this->security->isGranted(GameVoter::VIEW, $game)) {
            return ApiResponse::error(ApiErrorCode::NOT_FOUND, 'Game not found.');
        }

        if ($game->isRated() && !$game->isGameOver()) {
            $this->reportCheatingAttempt($game);

            return ApiResponse::error(ApiErrorCode::FORBIDDEN, 'Evaluation is not available during a rated game.');
        }

        return ApiResponse::ok([
            'evaluations' => $this->evaluationScheduler->scheduleMissing($game),
        ])->setStatusCode(Response::HTTP_ACCEPTED);
    }

    private function reportCheatingAttempt(Game $game): void
    {
        $user = $this->security->getUser();
        $context = [
            'game' => $game->getUuid()->toRfc4122(),
            'userId' => $user instanceof User ? $user->getId()->toRfc4122() : null,
            'username' => $user instanceof User ? $user->getUsername() : null,
        ];
        $message = 'Engine evaluation requested on a rated game in progress (cheating attempt).';

        $this->logger->critical($message, $context);

        // The Sentry bundle only registers in prod, so use the static API:
        // a no-op without a client, a `fatal` event with context otherwise.
        \Sentry\withScope(static function (\Sentry\State\Scope $scope) use ($message, $context): void {
            $scope->setLevel(\Sentry\Severity::fatal());
            $scope->setTag('cheating_attempt', 'rated_game_evaluation');
            $scope->setContext('evaluation_request', $context);
            \Sentry\captureMessage($message);
        });
    }
}
