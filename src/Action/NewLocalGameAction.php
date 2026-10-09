<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Form\LocalGameType;
use App\Model\ColorPreference;
use App\Model\OpponentType;
use App\Repository\GameRepository;
use App\Service\Analytics\AnalyticsRecorder;
use App\Service\GameFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

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
        private readonly AnalyticsRecorder $analyticsRecorder,
    ) {
    }

    #[Route(path: '/play/new', name: 'new_local_game', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): RedirectResponse|array
    {
        $form = $this->createForm(LocalGameType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $user = $this->getUser();

            if (!$user instanceof User) {
                // No account: the game runs in the browser (PlayGuestAction).
                $side = 'random' === $data['playerSide'] ? (0 === random_int(0, 1) ? 'white' : 'black') : $data['playerSide'];

                return $this->redirectToRoute('play_guest', [
                    'new' => OpponentType::AI === $data['opponentType'] ? 'ai' : 'hotseat',
                    'side' => $side,
                ]);
            }

            $colorPreference = match ($data['playerSide']) {
                'white' => ColorPreference::WHITE,
                'black' => ColorPreference::BLACK,
                default => ColorPreference::RANDOM,
            };

            $aiLevel = OpponentType::AI === $data['opponentType'] ? $data['aiLevel'] : null;

            $isFirstGame = 0 === $this->gameRepository->countForUser($user);
            $game = $this->gameFactory->createAiOrHotseatGame($user, $data['opponentType'], $colorPreference, $aiLevel, (bool) $data['liveEvaluation']);

            $this->entityManager->persist($game);
            $this->entityManager->flush();

            if ($isFirstGame) {
                $this->analyticsRecorder->firstGameStarted($user, $game);
            }

            $this->analyticsRecorder->gameStarted($user, $game, $data['opponentType'], $aiLevel);

            return $this->redirectToRoute('play', ['uuid' => $game->getUuid()]);
        }

        return ['form' => $form->createView()];
    }
}
