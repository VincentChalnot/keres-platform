<?php

declare(strict_types=1);

namespace App\Action\Social;

use App\Entity\User;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Service\Social\FriendListPayloadBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /friends/list` (05-social.md sec 3.6/9.2, 09-api-reference.md sec
 * 4.3). Pure read: accepted friends normalised to "the other side", the
 * incoming/outgoing `PENDING` inboxes (outgoing also carries silently
 * `DECLINED` rows - sec 3.5), and the caller's own block list.
 */
#[AsController]
readonly class FriendListAction
{
    public function __construct(
        private Security $security,
        private FriendListPayloadBuilder $payloadBuilder,
        private ClockInterface $clock,
    ) {
    }

    #[Route(path: '/friends/list', name: 'friends_list', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return ApiResponse::error(ApiErrorCode::AUTHENTICATION_REQUIRED, 'Authentication required.');
        }

        return ApiResponse::ok($this->payloadBuilder->build($user, $this->clock->now()));
    }
}
