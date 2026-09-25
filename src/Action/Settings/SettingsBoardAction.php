<?php

declare(strict_types=1);

namespace App\Action\Settings;

use App\Entity\User;
use App\Form\BoardSettingsType;
use App\Service\UserPreferencesManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** `GET|POST /settings/board` - Settings -> Board & gameplay. */
#[AsController]
class SettingsBoardAction extends AbstractController
{
    public function __construct(
        private readonly UserPreferencesManager $userPreferencesManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/settings/board', name: 'settings_board', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): RedirectResponse|array
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is required to edit settings.');
        }

        $preferences = $this->userPreferencesManager->getOrCreate($user);
        $form = $this->createForm(BoardSettingsType::class, $preferences);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $preferences->touch();
            $this->entityManager->flush();
            $this->addFlash('success', 'Board settings saved.');

            return $this->redirectToRoute('settings_board');
        }

        return [
            'section' => 'board',
            'form' => $form->createView(),
        ];
    }
}
