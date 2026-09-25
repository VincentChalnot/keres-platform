<?php

declare(strict_types=1);

namespace App\Action\Settings;

use App\Entity\User;
use App\Form\NotificationSettingsType;
use App\Model\Notification\NotificationPreferences;
use App\Service\UserPreferencesManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `GET|POST /settings/notifications` - Settings -> Notifications: one
 * in-app toggle per notification type (07-notifications.md sec 8, in-app
 * only for now) and the newsletter subscription.
 */
#[AsController]
class SettingsNotificationsAction extends AbstractController
{
    public function __construct(
        private readonly UserPreferencesManager $userPreferencesManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/settings/notifications', name: 'settings_notifications', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): RedirectResponse|array
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is required to edit settings.');
        }

        $preferences = $this->userPreferencesManager->getOrCreate($user);
        $notificationPreferences = NotificationPreferences::fromUser($user);

        $form = $this->createForm(NotificationSettingsType::class, [
            'inApp' => $notificationPreferences->inAppMap(),
            'newsletterOptIn' => $preferences->isNewsletterOptIn(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $notificationPreferences->withInApp($data['inApp'])->applyTo($user);
            $preferences->setNewsletterOptIn((bool) $data['newsletterOptIn']);
            $preferences->touch();
            $this->entityManager->flush();
            $this->addFlash('success', 'Notification settings saved.');

            return $this->redirectToRoute('settings_notifications');
        }

        return [
            'section' => 'notifications',
            'form' => $form->createView(),
        ];
    }
}
