<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Form\LocalGameType;
use App\Model\ChessExperience;
use App\Service\Ladder\NextBot;
use App\Service\Onboarding\ChessExperienceResolver;
use App\Service\Onboarding\TutorialContent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /tutorial`: the hybrid tutorial (D4 of `docs/onboarding-plan.md`), played by `assets/typescript/src/tutorial.ts` from
 * `public/tutorial/lessons.json`: a short track for chess players, interactive lessons for beginners, then "play the bot".
 * The track opens on the visitor's answer to "Do you play chess?" (chess players: the first; anyone else: the beginner
 * lessons) and can be switched on the page. Public; the page does not exist until the lessons file does
 * (`TutorialContent::isAvailable()`). The "Pieces & rules" reference sits below the lessons.
 */
#[AsController]
class TutorialAction extends AbstractController
{
    public function __construct(
        private readonly TutorialContent $tutorialContent,
        private readonly NextBot $nextBot,
        private readonly ChessExperienceResolver $chessExperience,
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
            'track' => ChessExperience::PLAYER === $this->chessExperience->current() ? 'chess' : 'beginner',
            // The ending offers the next bot as a one-click game (see _quick_game_form)
            'quickGame' => $this->createForm(LocalGameType::class, null, ['guest' => !$user instanceof User])->createView(),
            'nextBot' => $this->nextBot->forRequest($user instanceof User ? $user : null, $request),
        ];
    }
}
