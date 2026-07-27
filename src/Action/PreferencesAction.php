<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Form\UserPreferencesType;
use App\Service\UserPreferencesManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
class PreferencesAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPreferencesManager $userPreferencesManager,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route(
        path: '/preferences',
        name: 'preferences',
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request): RedirectResponse|array
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is required to edit preferences');
        }

        $preferences = $this->userPreferencesManager->getOrCreate($user);

        $form = $this->createForm(UserPreferencesType::class, $preferences, [
            'email' => $user->getEmail(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $preferences->touch();
            $this->entityManager->flush();

            return $this->redirectToRoute('preferences', ['saved' => 1]);
        }

        return [
            'form' => $form->createView(),
            'saved' => $request->query->getBoolean('saved'),
        ];
    }
}
