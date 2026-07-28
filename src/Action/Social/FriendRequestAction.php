<?php

declare(strict_types=1);

namespace App\Action\Social;

use App\Entity\User;
use App\Exception\CannotRequestSelfException;
use App\Exception\FriendshipBlockedException;
use App\Exception\FriendshipExistsException;
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

/** `POST /friends/request` (05-social.md sec 3.3/3.4, 09-api-reference.md sec 4.3). */
#[AsController]
readonly class FriendRequestAction
{
    public function __construct(
        private Security $security,
        private UserRepository $userRepository,
        private FriendshipManager $friendshipManager,
        private ClockInterface $clock,
        private RateLimiterFactory $friendRequestLimiter,
    ) {
    }

    #[Route(path: '/friends/request', name: 'friend_request', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return ApiResponse::error(ApiErrorCode::AUTHENTICATION_REQUIRED, 'Authentication required.');
        }

        if (!$this->friendRequestLimiter->create((string) $user->getId())->consume(1)->isAccepted()) {
            return ApiResponse::error(ApiErrorCode::RATE_LIMITED, 'Too many friend requests sent recently.');
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
            $outcome = $this->friendshipManager->request($user, $target, $this->clock->now());
        } catch (CannotRequestSelfException) {
            return ApiResponse::error(ApiErrorCode::CANNOT_REQUEST_SELF, 'You cannot send a friend request to yourself.');
        } catch (FriendshipBlockedException) {
            return ApiResponse::error(ApiErrorCode::BLOCKED, 'You have blocked this user.');
        } catch (FriendshipExistsException) {
            return ApiResponse::error(ApiErrorCode::FRIENDSHIP_EXISTS, 'You are already friends with this user.');
        }

        $body = ['friendship' => ['username' => $target->getUsername(), 'status' => $outcome->status]];

        return $outcome->created ? ApiResponse::created($body) : ApiResponse::ok($body);
    }
}
