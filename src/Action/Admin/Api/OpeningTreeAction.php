<?php

declare(strict_types=1);

namespace App\Action\Admin\Api;

use App\Repository\AdminStatsRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lazy per-level opening-tree expansion: returns the immediate child moves
 * of a BoardPosition. Never ships more than one level at a time — branching
 * factor can be large past ply 2-3.
 */
#[AsController]
class OpeningTreeAction
{
    public function __construct(
        private readonly AdminStatsRepository $adminStatsRepository,
    ) {
    }

    #[Route(path: '/admin/api/opening-tree', name: 'admin_opening_tree_api', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $positionId = $request->query->getInt('position');

        if ($positionId <= 0) {
            return new JsonResponse(['error' => 'Missing or invalid "position" query parameter.'], 400);
        }

        return new JsonResponse([
            'children' => $this->adminStatsRepository->getChildMoves($positionId),
        ]);
    }
}
