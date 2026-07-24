<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Repository\GameRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
class DashboardAction extends AbstractController
{
    public function __construct(
        private readonly GameRepository $gameRepository,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route(
        path: '/',
        name: 'dashboard',
        methods: ['GET'],
    )]
    public function __invoke(): array
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is required to view the dashboard');
        }

        $stats = $this->gameRepository->getFinishedGameStatsForOwner($user);
        $totalFinished = $stats['wins'] + $stats['losses'] + $stats['draws'];

        return [
            'recentGames' => $this->gameRepository->findRecentInProgressByOwner($user, 5),
            'inProgressCount' => $this->gameRepository->countInProgressByOwner($user),
            'stats' => $stats,
            'totalFinished' => $totalFinished,
            'winRate' => $totalFinished > 0 ? round(($stats['wins'] / $totalFinished) * 100) : null,
        ];
    }
}
