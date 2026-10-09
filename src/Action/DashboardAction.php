<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Repository\GameRepository;
use App\Service\Announcement\AnnouncementProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
class DashboardAction extends AbstractController
{
    public function __construct(
        private readonly GameRepository $gameRepository,
        private readonly AnnouncementProvider $announcementProvider,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route(
        path: '/dashboard',
        name: 'dashboard',
        methods: ['GET'],
    )]
    public function __invoke(): array
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is required to view the dashboard');
        }

        $stats = $this->gameRepository->getFinishedGameStatsForUser($user);
        $totalFinished = $stats['wins'] + $stats['losses'] + $stats['draws'];

        $recentGames = $this->gameRepository->findRecentInProgressForUser($user, 5);
        $this->gameRepository->preloadForListing($recentGames);

        return [
            'recentGames' => $recentGames,
            'inProgressCount' => $this->gameRepository->countInProgressForUser($user),
            'stats' => $stats,
            'announcements' => $this->announcementProvider->latest(),
            'totalFinished' => $totalFinished,
            'winRate' => $totalFinished > 0 ? round(($stats['wins'] / $totalFinished) * 100) : null,
        ];
    }
}
