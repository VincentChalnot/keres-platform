<?php

declare(strict_types=1);

namespace App\Action\Social;

use App\Entity\User;
use App\Exception\CannotBlockSelfException;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Repository\UserRepository;
use App\Service\Social\FriendshipManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/** `POST /friends/block` (05-social.md sec 4.1/4.5, T9). */
#[AsController]
readonly class FriendBlockAction
{
    public function __construct(
        private Security $security,
        private UserRepository $userRepository,
        private FriendshipManager $friendshipManager,
        private ClockInterface $clock,
        private RateLimiterFactory $socialActionLimiter,
    ) {
    }

    #[Route(path: '/friends/block', name: 'friend_block', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return ApiResponse::error(ApiErrorCode::AUTHENTICATION_REQUIRED, 'Authentication required.');
        }

        if (!$this->socialActionLimiter->create((string) $user->getId())->consume(1)->isAccepted()) {
            return ApiResponse::error(ApiErrorCode::RATE_LIMITED, 'Too many social actions recently.');
        }

        $data = json_decode($request->getContent(), true);
        $username = \is_array($data) && \is_string($data['username'] ?? null) ? $data['username'] : null;

        if (null === $username) {
            return ApiResponse::error(ApiErrorCode::VALIDATION_FAILED, 'A username is required.', [
                'violations' => [['field' => 'username', 'constraint' => 'not_blank', 'message' => 'This field is missing.']],
            ]);
        }

        $target = $this->userRepository->findOneByUsernameFold($username);

        if (null === $target) {
            return ApiResponse::error(ApiErrorCode::USER_NOT_FOUND, 'No account with that username.');
        }

        try {
            $this->friendshipManager->block($user, $target, $this->clock->now());
        } catch (CannotBlockSelfException) {
            return ApiResponse::error(ApiErrorCode::CANNOT_BLOCK_SELF, 'You cannot block yourself.');
        }

        return ApiResponse::ok(['blocked' => true]);
    }
}
