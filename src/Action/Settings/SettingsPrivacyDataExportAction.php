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

/**
 * `POST /settings/privacy/data-export` - T5: request intake only. Creates a
 * `Feedback` row (category `DATA_EXPORT_REQUEST`) for a human to process
 * manually and emails an admin notification through the async mailer (T1).
 * No automated export happens here or anywhere yet.
 *
 * The form itself is only ever rendered by `SettingsPrivacyAction`
 * (`form_start`'s `action` option points here); GET is intentionally not
 * supported.
 */
#[AsController]
class SettingsPrivacyDataExportAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminNotificationMailer $adminNotificationMailer,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/settings/privacy/data-export', name: 'settings_privacy_data_export', methods: ['POST'])]
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is required to request a data export.');
        }

        $form = $this->createForm(GdprRequestType::class, null, [
            'user_email' => $user->getEmail(),
            'submit_label' => 'Request data export',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $extra = $form->get('message')->getData();
            $message = \sprintf('Data export request from %s (%s).', $user->getUsername(), $user->getEmail());

            if (null !== $extra && '' !== trim($extra)) {
                $message .= "\n\nAdditional context:\n".$extra;
            }

            $feedback = new Feedback(FeedbackCategory::DATA_EXPORT_REQUEST, $message, $user);
            $this->entityManager->persist($feedback);
            $this->entityManager->flush();

            $this->adminNotificationMailer->sendGdprRequestNotification($feedback);

            $this->addFlash('success', 'Your data export request has been received. We will get back to you within 30 days.');
        } else {
            $this->addFlash('error', 'We could not submit your request. Please try again.');
        }

        return $this->redirectToRoute('settings_privacy');
    }
}
