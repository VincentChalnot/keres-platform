<?php

declare(strict_types=1);

namespace App\Action\Admin\Api;

use App\Entity\Move;
use App\Service\Evaluation\EvaluationScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Evaluation of one move edge for the opening explorer: the stored value, or
 * `null` after queueing its computation (through the first game that played
 * the edge) when the backfill command has not reached it yet - the explorer
 * asks again later. Never waits for the engine.
 */
#[AsController]
readonly class MoveEvaluationAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EvaluationScheduler $evaluationScheduler,
    ) {
    }

    #[Route(path: '/admin/api/move-evaluation', name: 'admin_move_evaluation_api', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $moveId = $request->query->getInt('move');
        $move = $moveId > 0 ? $this->entityManager->getRepository(Move::class)->find($moveId) : null;

        if (!$move instanceof Move) {
            return new JsonResponse(['error' => 'Missing or unknown "move" query parameter.'], 404);
        }

        $evaluation = $move->getEvaluation();

        if (null === $evaluation && !$this->evaluationScheduler->scheduleMove($move)) {
            // No game contains the edge any more: it will never be evaluated.
            return new JsonResponse(['evaluation' => null, 'pending' => false]);
        }

        return new JsonResponse(['evaluation' => $evaluation, 'pending' => null === $evaluation]);
    }
}
