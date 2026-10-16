<?php

declare(strict_types=1);

namespace App\Action;

use App\Engine\GameEngine;
use App\Entity\Game;
use App\Entity\User;
use App\Message\ProcessAiMoveMessage;
use App\Model\AnimationSpeed;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Repository\GameRepository;
use App\Repository\SeekRepository;
use App\Repository\UserPreferencesRepository;
use App\Security\Voter\GameVoter;
use App\Service\Game\ClockAdjudicator;
use App\Service\Game\GameStatePayloadBuilder;
use App\Service\Notification\NotificationCenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
class PlayAction extends AbstractController
{
    public function __construct(
        private readonly GameRepository $gameRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly ClockAdjudicator $clockAdjudicator,
        private readonly GameEngine $gameEngine,
        private readonly GameStatePayloadBuilder $payloadBuilder,
        private readonly NotificationCenter $notificationCenter,
        private readonly UserPreferencesRepository $userPreferencesRepository,
        private readonly SeekRepository $seekRepository,
        private readonly ClockInterface $clock,
        private readonly RateLimiterFactory $userAiLimiter,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/play/{uuid}',
        name: 'play',
        requirements: ['uuid' => Requirement::UUID],
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
            // Opening the game is reading its "your turn"/"game ended" rows.
            $this->notificationCenter->markGameRead($user, $game);
        }

        $colors = $game->getColorsForUser($user instanceof User ? $user : null);
        $playerColor = $colors[0] ?? PieceColor::WHITE;

        // AI auto-move trigger logic. Participants only: an admin looking at somebody else's AI game must not
        // make the AI answer for the player (a non-participant defaults to White above).
        if (
            (OpponentType::AI === $game->getOpponentType() || $game->isBotGame())
            && $user instanceof User
            && $game->isParticipant($user)
            && !$game->isGameOver()
            && $game->isWhiteTurn() !== (PieceColor::WHITE === $playerColor)
        ) {
            // The AI backend is billed: the AI's move is only requested within the user's `user_ai` budget. A refusal
            // dispatches nothing, the page says so, and reloading it asks again.
            if ($this->userAiLimiter->create($user->getId()->toRfc4122())->consume(1)->isAccepted()) {
                $this->messageBus->dispatch(
                    new ProcessAiMoveMessage(
                        $uuid,
                        $game->getGameMoves()->count(),
                    )
                );
            } else {
                $this->addFlash('error', $this->translator->trans('api_error.ai_rate_limited', [], 'frontend'));
            }
        }

        $movesData = $game->getMovesData();
        $movesBase64 = base64_encode($movesData->toBinary());

        // Seeds the client's authoritative clock/result state (mirrors what
        // a Mercure update carries) so the initial render - before the first
        // SSE message arrives - already reflects the true Game entity state
        // rather than only the board binary's engine-only verdict.
        $statePayload = $this->payloadBuilder->build($game, $this->gameEngine->getBoardMovesData($game));

        // Evaluations are for signed-in players: an anonymous spectator gets neither the live flag nor any stored value.
        if (!$user instanceof User) {
            $statePayload['liveEvaluation'] = false;
            $statePayload['evaluations'] = null;
        }

        // Settings -> Board & gameplay: how the board opens (both still toggle in-page).
        $preferences = $user instanceof User ? $this->userPreferencesRepository->findByUser($user) : null;

        return [
            'showCoordinates' => $preferences?->isShowBoardCoordinates() ?? true,
            'showThreats' => $preferences?->isShowOpponentThreatsOnHover() ?? true,
            'rotateOpponent' => $preferences?->isRotateOpponentPieces() ?? false,
            'animationSpeed' => ($preferences?->getPieceAnimationSpeed() ?? AnimationSpeed::FAST)->value,
            'game' => $game,
            'moves' => $movesBase64,
            'playerWhite' => PieceColor::WHITE === $playerColor,
            'gameStateBootstrap' => $this->payloadBuilder->encode($statePayload),
            'rematch' => $this->rematchState($game, $user instanceof User ? $user : null),
        ];
    }

    /**
     * Where the rematch of a finished game stands, for the game-over modal's button:
     * `started` (with the new game's uuid), `offered` (the opponent asked, the viewer's click accepts),
     * `requested` (the viewer asked, the opponent has not answered) or `none`.
     *
     * @return array{state: string, gameUuid: ?string}
     */
    private function rematchState(Game $game, ?User $user): array
    {
        if (!$game->isGameOver() || !$game->isParticipant($user)) {
            return ['state' => 'none', 'gameUuid' => null];
        }

        $rematch = $this->gameRepository->findRematchOf($game);

        if (null !== $rematch) {
            return ['state' => 'started', 'gameUuid' => $rematch->getUuid()->toRfc4122()];
        }

        $opponent = $game->getOpponentOf($user);

        if (null === $opponent) {
            return ['state' => 'none', 'gameUuid' => null];
        }

        $now = $this->clock->now();

        return match (true) {
            null !== $this->seekRepository->findOpenRematchSeek($game, $opponent, $now) => ['state' => 'offered', 'gameUuid' => null],
            null !== $this->seekRepository->findOpenRematchSeek($game, $user, $now) => ['state' => 'requested', 'gameUuid' => null],
            default => ['state' => 'none', 'gameUuid' => null],
        };
    }
}
