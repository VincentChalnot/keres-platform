<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Model\ChessExperience;
use App\Service\Onboarding\ChessExperienceCookie;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /play/guest` - an AI or hot-seat game without an account. The game
 * lives in the browser (`LocalGameAPI`, localStorage): nothing is written
 * server-side, so there is no `Game`/`User` row to clean up and nothing
 * reaches the rating pools. The engine is reached through the public
 * `/api/*` relays like any other board page. Signed-in players are sent
 * to the regular, persisted flow instead.
 */
#[AsController]
class PlayGuestAction extends AbstractController
{
    #[Route(path: '/play/guest', name: 'play_guest', methods: ['GET'])]
    public function __invoke(Request $request): RedirectResponse|array
    {
        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('new_local_game');
        }

        // Coach mode starts on for a browser that said it is new to chess (a saved choice of this browser wins, client-side).
        $experience = ChessExperienceCookie::read($request);

        return ['coach' => ChessExperience::BEGINNER === $experience, 'chessExperience' => $experience?->value];
    }
}
