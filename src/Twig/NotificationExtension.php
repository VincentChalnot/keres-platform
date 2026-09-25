<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `unread_notification_count()` for the header bell (07-notifications.md
 * sec 7.4). A function rather than a global so anonymous pages - which
 * never call it - never query.
 */
final class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly NotificationRepository $notificationRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notification_count', $this->unreadCount(...)),
        ];
    }

    public function unreadCount(): int
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->notificationRepository->countUnread($user) : 0;
    }
}
