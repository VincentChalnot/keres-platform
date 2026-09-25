<?php

declare(strict_types=1);

namespace App\Action\Notification;

use App\Entity\User;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /notifications/unread-count` (09-api-reference.md sec 4.5) - the
 * bell's fallback refresh, for rows written without a live frame
 * (`NotificationCenter::record()`) or a dropped `EventSource`.
 */
#[AsController]
readonly class UnreadCountAction
{
    public function __construct(
        private Security $security,
        private NotificationRepository $notificationRepository,
    ) {
    }

    #[Route(path: '/notifications/unread-count', name: 'notifications_unread_count', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return ApiResponse::error(ApiErrorCode::AUTHENTICATION_REQUIRED, 'Authentication required.');
        }

        return ApiResponse::ok(['unread' => $this->notificationRepository->countUnread($user)]);
    }
}
