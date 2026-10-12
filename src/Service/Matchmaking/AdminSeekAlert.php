<?php

declare(strict_types=1);

namespace App\Service\Matchmaking;

use App\Entity\Seek;
use App\Repository\UserRepository;
use App\Service\AdminNotificationMailer;
use App\Service\Notification\NotificationCenter;

/**
 * Tells the administrators that somebody posted a public seek: one in-app notification per admin (never to the
 * author) and one e-mail to ADMIN_NOTIFICATION_EMAIL. Post-commit, called by `SeekCreationService::create()` for a seek
 * that stays in the pool (a seek that paired at once waits for nobody).
 */
final readonly class AdminSeekAlert
{
    public function __construct(
        private UserRepository $userRepository,
        private NotificationCenter $notificationCenter,
        private AdminNotificationMailer $adminNotificationMailer,
    ) {
    }

    public function notify(Seek $seek): void
    {
        $author = $seek->getUser();

        foreach ($this->userRepository->findAdmins() as $admin) {
            if ($admin->getId()->equals($author->getId())) {
                continue;
            }

            $this->notificationCenter->seekPosted($admin, $seek);
        }

        // The address is the owner's: no point mailing them about their own seek.
        if (!\in_array('ROLE_ADMIN', $author->getRoles(), true)) {
            $this->adminNotificationMailer->sendSeekPosted($seek);
        }
    }
}
