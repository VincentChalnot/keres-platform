<?php

declare(strict_types=1);

namespace App\Action\Notification;

use App\Entity\User;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/** `POST /notifications/read-all` (09-api-reference.md sec 4.5). */
#[AsController]
readonly class NotificationReadAllAction
{
    public function __construct(
        private Security $security,
        private NotificationRepository $notificationRepository,
        private ClockInterface $clock,
        private RateLimiterFactory $notificationReadLimiter,
    ) {
    }

    #[Route(path: '/notifications/read-all', name: 'notifications_read_all', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return ApiResponse::error(ApiErrorCode::AUTHENTICATION_REQUIRED, 'Authentication required.');
        }

        if (!$this->notificationReadLimiter->create((string) $user->getId())->consume(1)->isAccepted()) {
            return ApiResponse::error(ApiErrorCode::RATE_LIMITED, 'Too many requests.');
        }

        $this->notificationRepository->markAllRead($user, $this->clock->now());

        return ApiResponse::ok(['unread' => 0]);
    }
}
