<?php

declare(strict_types=1);

namespace App\Action\Admin;

use App\Repository\AdminStatsRepository;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
class DashboardAction
{
    public function __construct(
        private readonly AdminStatsRepository $adminStatsRepository,
    ) {
    }

    #[Route(path: '/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function __invoke(): array
    {
        $outcomes = $this->adminStatsRepository->getOutcomeDistribution();

        return [
            'totalPlayers' => $this->adminStatsRepository->getTotalPlayers(),
            'connectedPlayers' => $this->adminStatsRepository->getConnectedPlayersCount(
                new \DateTimeImmutable('-5 minutes')
            ),
            'outcomes' => $outcomes,
            'moveCountDistribution' => $this->adminStatsRepository->getMoveCountDistribution(),
            'staleGames' => $this->adminStatsRepository->getStaleGames(),
        ];
    }
}
