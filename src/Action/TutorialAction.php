<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Form\LocalGameType;
use App\Service\Ladder\NextBot;
use App\Service\Onboarding\TutorialContent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /tutorial`: the slides (static SVG images exported from the admin board editor) then a guided problem played on
 * the real board (`assets/typescript/src/tutorial.ts`). Public. The material is authored by the operator and dropped in
 * `public/tutorial/`; until it is all there the page does not exist (`TutorialContent::isAvailable()`).
 */
#[AsController]
class TutorialAction extends AbstractController
{
    public function __construct(
        private readonly TutorialContent $tutorialContent,
        private readonly NextBot $nextBot,
    ) {
    }

    #[Route(path: '/tutorial', name: 'tutorial', methods: ['GET'])]
    public function __invoke(Request $request): array
    {
        if (!$this->tutorialContent->isAvailable()) {
            throw $this->createNotFoundException('The tutorial has no content yet.');
        }

        $user = $this->getUser();

        return [
            'slides' => TutorialContent::SLIDES,
            // The ending offers the next bot as a one-click game (see _quick_game_form)
            'quickGame' => $this->createForm(LocalGameType::class, null, ['guest' => !$user instanceof User])->createView(),
            'nextBot' => $this->nextBot->forRequest($user instanceof User ? $user : null, $request),
        ];
    }
}
