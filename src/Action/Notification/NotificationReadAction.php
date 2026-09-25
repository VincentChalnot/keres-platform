<?php

declare(strict_types=1);

namespace App\Action\Notification;

use App\Entity\User;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/** `POST /notifications/{uuid}/read` (09-api-reference.md sec 4.5). Already read is a 200 no-op. */
#[AsController]
readonly class NotificationReadAction
{
    public function __construct(
        private Security $security,
        private NotificationRepository $notificationRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private RateLimiterFactory $notificationReadLimiter,
    ) {
    }

    #[Route(path: '/notifications/{uuid}/read', name: 'notification_read', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function __invoke(string $uuid): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return ApiResponse::error(ApiErrorCode::AUTHENTICATION_REQUIRED, 'Authentication required.');
        }

        if (!$this->notificationReadLimiter->create((string) $user->getId())->consume(1)->isAccepted()) {
            return ApiResponse::error(ApiErrorCode::RATE_LIMITED, 'Too many requests.');
        }

        $notification = $this->notificationRepository->findOneForUser($user, Uuid::fromString($uuid));

        if (null === $notification) {
            return ApiResponse::error(ApiErrorCode::NOTIFICATION_NOT_FOUND, 'No such notification.');
        }

        $notification->markRead($this->clock->now());
        $this->entityManager->flush();

        return ApiResponse::ok(['unread' => $this->notificationRepository->countUnread($user)]);
    }
}
