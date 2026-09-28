<?php

declare(strict_types=1);

namespace App\Action\Settings;

use App\Entity\User;
use App\Form\GdprRequestType;
use App\Form\PrivacySettingsType;
use App\Repository\FriendshipRepository;
use App\Service\UserPreferencesManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `GET|POST /settings/privacy` - Settings -> Privacy: search visibility,
 * contact by email, the blocked-users list (the only place it is shown;
 * 05-social.md sec 4), and the two GDPR request forms (T5: data export,
 * account deletion). Unblocking goes through the JSON `friend_unblock`
 * route, wired in `lobby.ts`.
 *
 * The two GDPR forms are only *rendered* here - each posts to its own
 * dedicated single-purpose action
 * (`SettingsPrivacyDataExportAction`/`SettingsPrivacyAccountDeletionAction`)
 * via an explicit `action` attribute on `form_start`, so this action never
 * handles their submission.
 */
#[AsController]
class SettingsPrivacyAction extends AbstractController
{
    public function __construct(
        private readonly UserPreferencesManager $userPreferencesManager,
        private readonly FriendshipRepository $friendshipRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/settings/privacy', name: 'settings_privacy', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): RedirectResponse|array
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is required to edit settings.');
        }

        $preferences = $this->userPreferencesManager->getOrCreate($user);
        $form = $this->createForm(PrivacySettingsType::class, $preferences);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $preferences->touch();
            $this->entityManager->flush();
            $this->addFlash('success', 'Privacy settings saved.');

            return $this->redirectToRoute('settings_privacy');
        }

        $dataExportForm = $this->createForm(GdprRequestType::class, null, [
            'action' => $this->generateUrl('settings_privacy_data_export'),
            'user_email' => $user->getEmail(),
            'submit_label' => 'Request data export',
        ]);
        $accountDeletionForm = $this->createForm(GdprRequestType::class, null, [
            'action' => $this->generateUrl('settings_privacy_account_deletion'),
            'user_email' => $user->getEmail(),
            'submit_label' => 'Request account deletion',
        ]);

        return [
            'section' => 'privacy',
            'form' => $form->createView(),
            'user' => $user,
            'blockedUsers' => $this->friendshipRepository->findBlockedByUser($user),
            'dataExportForm' => $dataExportForm->createView(),
            'accountDeletionForm' => $accountDeletionForm->createView(),
        ];
    }
}
