<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Service\Game\BotGameManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /play/bot-game/resign` - the "Resign" of the home page's running-bot-game card: ends the player's bot game
 * (`BotGameManager::retire()`: aborted while it would not count anyway, resigned otherwise) and goes back home.
 */
#[AsController]
class ResignBotGameAction extends AbstractController
{
    public function __construct(private readonly BotGameManager $botGameManager)
    {
    }

    #[Route(path: '/play/bot-game/resign', name: 'resign_bot_game', methods: ['POST'])]
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User || !$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $game = $this->botGameManager->findCurrent($user);

        if (null !== $game) {
            $this->botGameManager->retire($game, $user);
        }

        return $this->redirectToRoute('index');
    }
}
