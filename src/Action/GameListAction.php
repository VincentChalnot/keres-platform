<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Repository\GameRepository;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `GET /games` (04-matchmaking.md sec 9.2) - the two flat lists from the
 * old `NewGameAction`, now `GamePlayer`-scoped and paginated
 * (00-overview.md sec 6).
 */
#[AsController]
class GameListAction extends AbstractController
{
    private const int PER_PAGE = 20;

    public function __construct(
        private readonly GameRepository $gameRepository,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/games', name: 'game_list', methods: ['GET'])]
    public function __invoke(Request $request): array
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is required to view games');
        }

        $status = 'finished' === $request->query->get('status') ? 'finished' : 'in_progress';
        $page = max(1, $request->query->getInt('page', 1));

        $queryBuilder = 'finished' === $status
            ? $this->gameRepository->queryFinishedForUser($user)
            : $this->gameRepository->queryOngoingForUser($user);

        $pager = new Pagerfanta(new QueryAdapter($queryBuilder));
        $pager->setMaxPerPage(self::PER_PAGE);
        $pager->setCurrentPage(min($page, max(1, $pager->getNbPages())));

        return [
            'status' => $status,
            'games' => $pager,
        ];
    }
}
