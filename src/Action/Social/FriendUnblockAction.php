<?php

declare(strict_types=1);

namespace App\Action\Social;

use App\Entity\User;
use App\Exception\FriendshipNotFoundException;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Repository\UserRepository;
use App\Service\Social\FriendshipManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/** `POST /friends/{username}/unblock` (05-social.md sec 4.3, T10). */
#[AsController]
readonly class FriendUnblockAction
{
    public function __construct(
        private Security $security,
        private UserRepository $userRepository,
        private FriendshipManager $friendshipManager,
        private RateLimiterFactory $socialActionLimiter,
    ) {
    }

    #[Route(path: '/friends/{username}/unblock', name: 'friend_unblock', requirements: ['username' => '[A-Za-z0-9_-]{3,32}'], methods: ['POST'])]
    public function __invoke(string $username): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return ApiResponse::error(ApiErrorCode::AUTHENTICATION_REQUIRED, 'Authentication required.');
        }

        if (!$this->socialActionLimiter->create((string) $user->getId())->consume(1)->isAccepted()) {
            return ApiResponse::error(ApiErrorCode::RATE_LIMITED, 'Too many social actions recently.');
        }

        $target = $this->userRepository->findOneByUsernameFold($username);

        if (null === $target) {
            return ApiResponse::error(ApiErrorCode::USER_NOT_FOUND, 'No account with that username.');
        }

        try {
            $this->friendshipManager->unblock($user, $target);
        } catch (FriendshipNotFoundException) {
            return ApiResponse::error(ApiErrorCode::FRIENDSHIP_NOT_FOUND, 'No block on that user.');
        }

        return ApiResponse::ok(['blocked' => false]);
    }
}
