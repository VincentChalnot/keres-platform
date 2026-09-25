<?php

declare(strict_types=1);

namespace App\Action\Notification;

use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\Notification\NotificationFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** `GET /notifications` - the full, paginated inbox behind the bell's "See all". */
#[AsController]
class NotificationPageAction extends AbstractController
{
    private const int PER_PAGE = 30;

    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly NotificationFormatter $formatter,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/notifications', name: 'notifications', methods: ['GET'])]
    public function __invoke(Request $request): array
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is required to view notifications.');
        }

        $total = $this->notificationRepository->countForUser($user);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, $request->query->getInt('page', 1)));

        return [
            'notifications' => $this->formatter->formatAll(
                $this->notificationRepository->findLatestForUser($user, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            ),
            'page' => $page,
            'pages' => $pages,
            'unread' => $this->notificationRepository->countUnread($user),
        ];
    }
}
