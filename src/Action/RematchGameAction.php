<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Model\ColorPreference;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Repository\GameRepository;
use App\Security\Voter\GameVoter;
use App\Service\Analytics\AnalyticsRecorder;
use App\Service\GameFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /play/{uuid}/rematch` - "Quick rematch" of the game-over modal: a new AI or hot-seat game with the
 * parameters of the finished one (opponent type, AI level, live evaluation) and the colours swapped, then a
 * redirect to it. Multiplayer games are not covered: a rematch there needs the opponent's consent.
 */
#[AsController]
class RematchGameAction extends AbstractController
{
    public function __construct(
        private readonly GameRepository $gameRepository,
        private readonly GameFactory $gameFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly AnalyticsRecorder $analyticsRecorder,
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

        if (!$game->isGameOver() || OpponentType::MULTIPLAYER === $game->getOpponentType()) {
            return $this->redirectToRoute('play', ['uuid' => $game->getUuid()]);
        }

        $colorPreference = PieceColor::WHITE === $game->getCreatorColor() ? ColorPreference::BLACK : ColorPreference::WHITE;
        $rematch = $this->gameFactory->createAiOrHotseatGame(
            $user,
            $game->getOpponentType(),
            $colorPreference,
            $game->getAiLevel(),
            $game->isLiveEvaluationEnabled(),
        );

        $this->entityManager->persist($rematch);
        $this->entityManager->flush();

        $this->analyticsRecorder->gameStarted($user, $rematch, $game->getOpponentType(), $game->getAiLevel());

        return $this->redirectToRoute('play', ['uuid' => $rematch->getUuid()]);
    }
}
