<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Message\ProcessAiMoveMessage;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Repository\GameRepository;
use App\Security\Voter\GameVoter;
use App\Service\Game\ClockAdjudicator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
class PlayAction extends AbstractController
{
    public function __construct(
        private readonly GameRepository $gameRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly ClockAdjudicator $clockAdjudicator,
    ) {
    }

    #[Route(
        path: '/play/{uuid}',
        name: 'play',
    )]
    public function __(string $uuid): array
    {
        $game = $this->gameRepository->findForPlay(Uuid::fromString($uuid));

        if (!$game) {
            throw $this->createNotFoundException('Game not found');
        }

        if (!$this->isGranted(GameVoter::VIEW, $game)) {
            throw $this->createNotFoundException('Game not found');
        }

        $user = $this->getUser();

        // Path (b), authenticated participants only (03-time-control.md
        // sec 5.2): never for anonymous spectators - GAME_VIEW is public,
        // and letting an anonymous page load finalise a rated game hands a
        // write-amplification lever to anyone holding a game UUID.
        if ($user instanceof User && $game->isParticipant($user)) {
            $this->clockAdjudicator->adjudicate($game);
        }

        $colors = $game->getColorsForUser($user instanceof User ? $user : null);
        $playerColor = $colors[0] ?? PieceColor::WHITE;

        // AI auto-move trigger logic
        if (
            OpponentType::AI === $game->getOpponentType()
            && !$game->isGameOver()
            && $game->isWhiteTurn() !== (PieceColor::WHITE === $playerColor)
        ) {
            $this->messageBus->dispatch(
                new ProcessAiMoveMessage(
                    $uuid,
                    $game->getGameMoves()->count(),
                )
            );
        }

        $movesData = $game->getMovesData();
        $movesBase64 = base64_encode($movesData->toBinary());

        return [
            'game' => $game,
            'moves' => $movesBase64,
            'playerWhite' => PieceColor::WHITE === $playerColor,
        ];
    }
}
