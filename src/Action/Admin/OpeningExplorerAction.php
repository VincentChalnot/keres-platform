<?php

declare(strict_types=1);

namespace App\Action\Admin;

use App\Entity\BoardPosition;
use App\Repository\AdminStatsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page shell for the opening explorer. Resolves the shared root
 * BoardPosition (see AdminStatsRepository::getRootBoardPositionId) and hands
 * it to the client — all tree traversal beyond that happens through the
 * OpeningTreeAction/OpeningStatsAction JSON endpoints, decoded client-side
 * (per platform/AGENTS.md: no game-rule knowledge in PHP, only the
 * TypeScript renderer may decode MoveData/BoardData semantics).
 */
#[AsController]
class OpeningExplorerAction
{
    public function __construct(
        private readonly AdminStatsRepository $adminStatsRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/admin/opening-explorer', name: 'admin_opening_explorer', methods: ['GET'])]
    public function __invoke(): array
    {
        $rootId = $this->adminStatsRepository->getRootBoardPositionId();
        $rootData = null;

        if (null !== $rootId) {
            $root = $this->entityManager->getRepository(BoardPosition::class)->find($rootId);

            if (null !== $root) {
                $rootData = base64_encode($root->getBoardPositionData());
            }
        }

        return [
            'rootPositionId' => $rootId,
            'rootPositionData' => $rootData,
        ];
    }
}
