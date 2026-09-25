<?php

declare(strict_types=1);

namespace App\Action\Notification;

use App\Entity\User;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Repository\NotificationRepository;
use App\Service\Notification\NotificationFormatter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /notifications/list` (09-api-reference.md sec 4.5) - what the
 * header bell's dropdown renders: the latest rows plus the unread count.
 */
#[AsController]
readonly class NotificationListAction
{
    private const int DEFAULT_LIMIT = 10;
    private const int MAX_LIMIT = 50;

    public function __construct(
        private Security $security,
        private NotificationRepository $notificationRepository,
        private NotificationFormatter $formatter,
    ) {
    }

    #[Route(path: '/notifications/list', name: 'notifications_list', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return ApiResponse::error(ApiErrorCode::AUTHENTICATION_REQUIRED, 'Authentication required.');
        }

        $limit = max(1, min(self::MAX_LIMIT, $request->query->getInt('limit', self::DEFAULT_LIMIT)));

        return ApiResponse::ok([
            'notifications' => $this->formatter->formatAll($this->notificationRepository->findLatestForUser($user, $limit)),
            'unread' => $this->notificationRepository->countUnread($user),
        ]);
    }
}
