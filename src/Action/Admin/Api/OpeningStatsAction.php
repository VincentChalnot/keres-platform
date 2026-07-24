<?php

declare(strict_types=1);

namespace App\Action\Admin\Api;

use App\Repository\AdminStatsRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Leaf move-statistics fetch: same shape as OpeningTreeAction (one more
 * level of children, for continued exploration past the depth-4 tree) plus
 * aggregate win/lose/draw/in-progress stats — scoped to games whose move
 * sequence actually reaches this exact BoardPosition at this exact ply
 * depth, relative to whoever is to move next.
 */
#[AsController]
class OpeningStatsAction
{
    public function __construct(
        private readonly AdminStatsRepository $adminStatsRepository,
    ) {
    }

    #[Route(path: '/admin/api/opening-stats', name: 'admin_opening_stats_api', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $positionId = $request->query->getInt('position');
        $ply = $request->query->getInt('ply');

        if ($positionId <= 0 || $ply <= 0) {
            return new JsonResponse(['error' => 'Missing or invalid "position"/"ply" query parameters.'], 400);
        }

        return new JsonResponse([
            'children' => $this->adminStatsRepository->getChildMoves($positionId),
            'stats' => $this->adminStatsRepository->getLeafStats($positionId, $ply),
        ]);
    }
}
