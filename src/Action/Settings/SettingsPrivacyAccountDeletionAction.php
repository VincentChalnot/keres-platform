<?php

declare(strict_types=1);

namespace App\Action\Settings;

use App\Entity\Feedback;
use App\Entity\User;
use App\Form\GdprRequestType;
use App\Model\FeedbackCategory;
use App\Service\AdminNotificationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `POST /settings/privacy/delete-account` - T5: request intake only.
 * Creates a `Feedback` row (category `ACCOUNT_DELETION_REQUEST`) for a
 * human to process manually and emails an admin notification through the
 * async mailer (T1). No automated account deletion or anonymization
 * happens here or anywhere yet - `User` has no `deletedAt`/anonymization
 * mechanism.
 *
 * The form itself is only ever rendered by `SettingsPrivacyAction`
 * (`form_start`'s `action` option points here); GET is intentionally not
 * supported.
 */
#[AsController]
class SettingsPrivacyAccountDeletionAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminNotificationMailer $adminNotificationMailer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/settings/privacy/delete-account', name: 'settings_privacy_account_deletion', methods: ['POST'])]
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is required to request account deletion.');
        }

        $form = $this->createForm(GdprRequestType::class, null, [
            'user_email' => $user->getEmail(),
            'submit_label' => 'gdpr.submit_deletion',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $extra = $form->get('message')->getData();
            $message = \sprintf('Account deletion request from %s (%s).', $user->getUsername(), $user->getEmail());

            if (null !== $extra && '' !== trim($extra)) {
                $message .= "\n\nAdditional context:\n".$extra;
            }

            $feedback = new Feedback(FeedbackCategory::ACCOUNT_DELETION_REQUEST, $message, $user);
            $this->entityManager->persist($feedback);
            $this->entityManager->flush();

            $this->adminNotificationMailer->sendGdprRequestNotification($feedback);

            $this->addFlash('success', $this->translator->trans('flash.deletion_requested', [], 'flashes'));
        } else {
            $this->addFlash('error', $this->translator->trans('flash.request_failed', [], 'flashes'));
        }

        return $this->redirectToRoute('settings_privacy');
    }
}
