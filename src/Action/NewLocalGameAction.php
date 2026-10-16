<?php

declare(strict_types=1);

namespace App\Action;

use App\Action\Lobby\LobbyAction;
use App\Entity\User;
use App\Form\LocalGameType;
use App\Model\ColorPreference;
use App\Model\OpponentType;
use App\Model\TimeControl;
use App\Repository\GameRepository;
use App\Repository\UserRepository;
use App\Service\Analytics\AnalyticsRecorder;
use App\Service\BotTournament\BotAccounts;
use App\Service\Game\BotGameManager;
use App\Service\GameFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `GET|POST /play/new` (04-matchmaking.md sec 9.2) - the AI/hot-seat half
 * of the old `NewGameAction`. Open to anonymous visitors, whose game is
 * handed to the browser-only `PlayGuestAction` instead of persisted. `HUMAN` games come from the lobby or a
 * challenge, never this form (`LocalGameType`'s own docblock).
 */
#[AsController]
class NewLocalGameAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GameFactory $gameFactory,
        private readonly GameRepository $gameRepository,
        private readonly UserRepository $userRepository,
        private readonly BotGameManager $botGameManager,
        private readonly BotAccounts $botAccounts,
        private readonly AnalyticsRecorder $analyticsRecorder,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/play/new', name: 'new_local_game', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): RedirectResponse|array
    {
        $guest = !$this->getUser() instanceof User;
        $form = $this->createForm(LocalGameType::class, null, ['guest' => $guest]);
        $form->handleRequest($request);

        if ($guest && $form->isSubmitted() && OpponentType::AI === $form->get('opponentType')->getData() && $form->get('aiLevel')->getData() > LocalGameType::GUEST_MAX_AI_LEVEL) {
            $form->get('aiLevel')->addError(new FormError($this->translator->trans('new_local_game.form.ai_level_guest_error', [], 'game')));
        }

        // A rated game against a bot is a multiplayer game against that level's bot account.
        $bot = null;

        if ($form->isSubmitted() && !$guest && OpponentType::AI === $form->get('opponentType')->getData() && $form->get('rated')->getData()) {
            $botId = $this->botAccounts->allIds()[$form->get('aiLevel')->getData()] ?? null;
            $bot = null !== $botId ? $this->userRepository->find($botId) : null;

            if (null === $bot) {
                $form->get('aiLevel')->addError(new FormError($this->translator->trans('new_local_game.form.bot_unavailable', [], 'game')));
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $user = $this->getUser();

            if (!$user instanceof User) {
                // No account: the game runs in the browser (PlayGuestAction).
                $side = 'random' === $data['playerSide'] ? (0 === random_int(0, 1) ? 'white' : 'black') : $data['playerSide'];

                return $this->redirectToRoute('play_guest', [
                    'new' => OpponentType::AI === $data['opponentType'] ? 'ai' : 'hotseat',
                    'side' => $side,
                    'level' => OpponentType::AI === $data['opponentType'] ? $data['aiLevel'] : null,
                ]);
            }

            $colorPreference = match ($data['playerSide']) {
                'white' => ColorPreference::WHITE,
                'black' => ColorPreference::BLACK,
                default => ColorPreference::RANDOM,
            };

            $aiLevel = OpponentType::AI === $data['opponentType'] ? $data['aiLevel'] : null;

            // One bot game at a time (D5), casual or rated. The server is the source of truth: the page only mirrors
            // it by offering to resume the running game or to end it and start this one in the same request.
            if (null !== $aiLevel && null !== ($current = $this->botGameManager->findCurrent($user))) {
                if (!$form->get('replaceCurrent')->isClicked()) {
                    return ['form' => $form->createView(), 'conflict' => $current];
                }

                $this->botGameManager->retire($current, $user);
            }

            $isFirstGame = 0 === $this->gameRepository->countForUser($user);

            if (null !== $bot) {
                $preset = LobbyAction::PRESETS[$data['cadence']];
                $game = $this->gameFactory->createBotGame($user, $bot, $aiLevel, $colorPreference, TimeControl::realtime($preset['initialMinutes'] * 60, $preset['incrementSeconds']));
            } else {
                $game = $this->gameFactory->createAiOrHotseatGame($user, $data['opponentType'], $colorPreference, $aiLevel, (bool) $data['liveEvaluation']);
            }

            $this->entityManager->persist($game);
            $this->entityManager->flush();

            if ($isFirstGame) {
                $this->analyticsRecorder->firstGameStarted($user, $game);
            }

            $this->analyticsRecorder->gameStarted($user, $game, $data['opponentType'], $aiLevel);

            return $this->redirectToRoute('play', ['uuid' => $game->getUuid()]);
        }

        return ['form' => $form->createView(), 'conflict' => null];
    }
}
