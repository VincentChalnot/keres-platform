<?php

declare(strict_types=1);

namespace App\Action\Profile;

use App\Entity\Game;
use App\Entity\User;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Repository\GameRepository;
use App\Repository\UserRepository;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /@/{username}/games` (05-social.md sec 9.3, 09-api-reference.md sec
 * 4.6). Public, paginated JSON companion to `ProfilePageAction`'s
 * server-rendered first page - lets the profile page (or any other
 * client) fetch further pages without a full reload.
 */
#[AsController]
readonly class ProfileGamesAction
{
    private const int DEFAULT_PER_PAGE = 20;
    private const int MAX_PER_PAGE = 50;

    private const array STATUSES = ['all', 'in_progress', 'finished'];

    public function __construct(
        private Security $security,
        private UserRepository $userRepository,
        private GameRepository $gameRepository,
    ) {
    }

    #[Route(
        path: '/@/{username}/games',
        name: 'profile_games',
        requirements: ['username' => '[A-Za-z0-9_-]{3,32}'],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $username): JsonResponse
    {
        $subject = $this->userRepository->findOneByUsernameFold($username);

        if (null === $subject) {
            return ApiResponse::error(ApiErrorCode::USER_NOT_FOUND, 'No account with that username.');
        }

        $viewerUser = $this->security->getUser();
        $viewer = $viewerUser instanceof User ? $viewerUser : null;
        $isSelf = null !== $viewer && $viewer === $subject;

        $includeHidden = $request->query->getBoolean('includeHidden', false);

        if ($includeHidden && !$isSelf) {
            return ApiResponse::error(ApiErrorCode::FORBIDDEN, 'Only the profile owner may include hidden games.');
        }

        $status = $request->query->get('status', 'all');
        $status = \in_array($status, self::STATUSES, true) ? $status : 'all';
        $perPage = min(self::MAX_PER_PAGE, max(1, $request->query->getInt('perPage', self::DEFAULT_PER_PAGE)));
        $page = max(1, $request->query->getInt('page', 1));

        $queryBuilder = $this->gameRepository->queryProfileGamesForUser($subject, $isSelf, $includeHidden);

        if ('in_progress' === $status) {
            $queryBuilder->andWhere('g.gameOverAt IS NULL');
        } elseif ('finished' === $status) {
            $queryBuilder->andWhere('g.gameOverAt IS NOT NULL');
        }

        $pager = new Pagerfanta(new QueryAdapter($queryBuilder));
        $pager->setMaxPerPage($perPage);
        $pager->setCurrentPage(min($page, max(1, $pager->getNbPages())));

        $games = [];

        foreach ($pager->getCurrentPageResults() as $game) {
            $games[] = $this->buildGame($game, $subject);
        }

        return ApiResponse::ok(['games' => $games], [
            'page' => $pager->getCurrentPage(),
            'perPage' => $perPage,
            'totalCount' => $pager->getNbResults(),
        ]);
    }

    /** @return array<string, mixed> */
    private function buildGame(Game $game, User $subject): array
    {
        // Hot-seat returns both colours on self view (Game::getColorsForUser
        // docblock); the first is the one the subject's own seat sorts to
        // first (players are ordered by colour, white first).
        $color = $game->getColorsForUser($subject)[0] ?? null;

        $opponent = null;

        foreach ($game->getPlayers() as $player) {
            if ($player->getColor() !== $color) {
                $opponent = $player->getUser();
                break;
            }
        }

        $result = match (true) {
            null === $game->getGameOverAt() => null,
            $game->isDraw() => 'draw',
            $game->isWhiteWins() => 'white',
            default => 'black',
        };

        return [
            'uuid' => $game->getUuid()->toRfc4122(),
            'opponent' => [
                'username' => $opponent?->getUsername(),
                'displayName' => $opponent?->getDisplayName(),
                'avatarUrl' => $opponent?->getAvatarUrl(),
            ],
            'color' => null !== $color ? strtolower($color->name) : null,
            'result' => $result,
            'endReason' => strtolower($game->getEndReason()->name),
            'speedCategory' => null !== $game->getSpeedCategory() ? strtolower($game->getSpeedCategory()->name) : null,
            'rated' => $game->isRated(),
            // No UserRating/rating history until Phase 5 (06-rating.md) -
            // never fabricated, always null until that lands.
            'ratingDelta' => null,
            'movesCount' => $game->getGameMoves()->count(),
            'createdAt' => (int) $game->getCreatedAt()->format('Uu'),
            'gameOverAt' => null !== $game->getGameOverAt() ? (int) $game->getGameOverAt()->format('Uu') : null,
        ];
    }
}
