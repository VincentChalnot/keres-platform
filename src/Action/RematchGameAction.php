<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Model\OpponentType;
use App\Repository\FriendshipRepository;
use App\Repository\GameRepository;
use App\Security\Voter\GameVoter;
use App\Service\Game\RematchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /play/{uuid}/rematch` - "Rematch" of the game-over modal (see `RematchService`). Redirects to the
 * rematch game as soon as it exists (AI and hot-seat: at once; multiplayer: once both players have clicked,
 * including a click on a rematch the other player already asked for), otherwise to the lobby, where the
 * request waits for the opponent's answer like a posted seek.
 */
#[AsController]
class RematchGameAction extends AbstractController
{
    public function __construct(
        private readonly GameRepository $gameRepository,
        private readonly FriendshipRepository $friendshipRepository,
        private readonly RematchService $rematchService,
    ) {
    }

    #[Route(
        path: '/play/{uuid}/rematch',
        name: 'rematch_game',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function __invoke(string $uuid): RedirectResponse
    {
        $game = $this->gameRepository->findByUuid(Uuid::fromString($uuid));

        if (!$game || !$this->isGranted(GameVoter::PARTICIPATE, $game)) {
            throw $this->createNotFoundException('Game not found');
        }

        $user = $this->getUser();
        \assert($user instanceof User);

        if (!$game->isGameOver()) {
            return $this->redirectToRoute('play', ['uuid' => $game->getUuid()]);
        }

        if (OpponentType::MULTIPLAYER === $game->getOpponentType()) {
            $opponent = $game->getOpponentOf($user);

            if (null === $opponent || $this->friendshipRepository->isBlockedEitherWay($user, $opponent)) {
                return $this->redirectToRoute('play', ['uuid' => $game->getUuid()]);
            }
        }

        $rematch = $this->rematchService->request($user, $game);

        return null === $rematch
            ? $this->redirectToRoute('lobby')
            : $this->redirectToRoute('play', ['uuid' => $rematch->getUuid()]);
    }
}
