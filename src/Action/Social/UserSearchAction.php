<?php

declare(strict_types=1);

namespace App\Action\Social;

use App\Entity\User;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Model\MultiplayerLimits;
use App\Model\SpeedCategory;
use App\Repository\UserRepository;
use App\Service\Rating\RatingUpdater;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /players/search?q=` (05-social.md sec 2.2). Username-prefix only:
 * an `@` in `q` returns an empty result set rather than attempting a
 * lookup - a pasted email address gets the same shape as nonsense input.
 */
#[AsController]
readonly class UserSearchAction
{
    private const int DEFAULT_LIMIT = 10;
    private const int MAX_LIMIT = 20;

    public function __construct(
        private Security $security,
        private UserRepository $userRepository,
        private ClockInterface $clock,
        private RateLimiterFactory $friendSearchLimiter,
        private RatingUpdater $ratingUpdater,
    ) {
    }

    #[Route(path: '/players/search', name: 'user_search', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return ApiResponse::error(ApiErrorCode::AUTHENTICATION_REQUIRED, 'Authentication required.');
        }

        if (!$this->friendSearchLimiter->create((string) $user->getId())->consume(1)->isAccepted()) {
            return ApiResponse::error(ApiErrorCode::RATE_LIMITED, 'Too many searches recently.');
        }

        $q = trim((string) $request->query->get('q', ''));

        if (str_contains($q, '@')) {
            return ApiResponse::ok(['players' => []]);
        }

        if (\strlen($q) < MultiplayerLimits::USERNAME_MIN_SEARCH_PREFIX) {
            return ApiResponse::error(ApiErrorCode::SEARCH_PREFIX_TOO_SHORT, \sprintf('Search prefix must be at least %d characters.', MultiplayerLimits::USERNAME_MIN_SEARCH_PREFIX));
        }

        $limit = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', self::DEFAULT_LIMIT)));
        $now = $this->clock->now();

        $players = array_map(
            function (User $u) use ($now): array {
                // No time control is chosen at search time, so there is no
                // real per-category rating to show yet (06-rating.md sec 5.1
                // has one pool per SpeedCategory, never a global one).
                // BLITZ is the platform's headline category for this
                // context-free display - same convention as chess.com/lichess.
                $rating = $this->ratingUpdater->currentRating($u, SpeedCategory::BLITZ, $now);

                return [
                    'username' => $u->getUsername(),
                    'rating' => $rating->display(),
                    'provisional' => $rating->isProvisional(),
                    'online' => $u->isOnline($now),
                ];
            },
            $this->userRepository->searchByUsernamePrefix($q, $user, $limit),
        );

        return ApiResponse::ok(['players' => $players]);
    }
}
