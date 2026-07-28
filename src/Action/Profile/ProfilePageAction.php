<?php

declare(strict_types=1);

namespace App\Action\Profile;

use App\Entity\User;
use App\Model\MultiplayerLimits;
use App\Model\Social\RatingSummary;
use App\Repository\GameRepository;
use App\Repository\UserRepository;
use App\Service\Social\FriendshipManager;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /@/{username}` (05-social.md sec 9.1, 09-api-reference.md sec
 * 3.7/4.6). Public: multiplayer games are already publicly viewable
 * (contract sec 4.3) and a profile link must work pasted into a chat, so
 * this never requires authentication - `getUser()` is handled as nullable
 * throughout, matching `LobbyAction`.
 */
#[AsController]
class ProfilePageAction extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly GameRepository $gameRepository,
        private readonly FriendshipManager $friendshipManager,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: '/@/{username}',
        name: 'profile',
        requirements: ['username' => '[A-Za-z0-9_-]{3,32}'],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $username): RedirectResponse|array
    {
        $subject = $this->userRepository->findOneByUsernameFold($username);

        if (null === $subject) {
            throw $this->createNotFoundException('No account with that username.');
        }

        // Casing differs from the stored handle - 301 to the canonical URL
        // so link equity and caching converge on one form (sec 9.1).
        if ($subject->getUsername() !== $username) {
            return $this->redirectToRoute('profile', ['username' => $subject->getUsername()], 301);
        }

        $viewerUser = $this->getUser();
        $viewer = $viewerUser instanceof User ? $viewerUser : null;
        $isSelf = null !== $viewer && $viewer === $subject;
        $now = $this->clock->now();

        $queryBuilder = $this->gameRepository->queryProfileGamesForUser($subject, $isSelf);
        $pager = new Pagerfanta(new QueryAdapter($queryBuilder));
        $pager->setMaxPerPage(MultiplayerLimits::PROFILE_GAMES_PER_PAGE);
        $page = max(1, $request->query->getInt('page', 1));
        $pager->setCurrentPage(min($page, max(1, $pager->getNbPages())));

        return [
            'subject' => $subject,
            'viewer' => $viewer,
            'isSelf' => $isSelf,
            // Never computed for an anonymous viewer or on self view - the
            // template uses `relationship is null` to hide every
            // friend/block action at once (sec 9.1).
            'relationship' => (null !== $viewer && !$isSelf) ? $this->friendshipManager->relationOf($viewer, $subject) : null,
            'ratings' => RatingSummary::defaultsForAllCategories(),
            'stats' => $this->gameRepository->getFinishedGameStatsForUser($subject),
            'online' => $subject->isOnline($now),
            'games' => $pager,
        ];
    }
}
