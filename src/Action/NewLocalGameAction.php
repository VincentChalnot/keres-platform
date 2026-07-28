<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Form\LocalGameType;
use App\Model\ColorPreference;
use App\Service\GameFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET|POST /play/new` (04-matchmaking.md sec 9.2) - the AI/hot-seat half
 * of the old `NewGameAction`. `HUMAN` games come from the lobby or a
 * challenge, never this form (`LocalGameType`'s own docblock).
 */
#[AsController]
class NewLocalGameAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GameFactory $gameFactory,
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
                throw $this->createAccessDeniedException('User is required to create a game');
            }

            $colorPreference = match ($data['playerSide']) {
                'white' => ColorPreference::WHITE,
                'black' => ColorPreference::BLACK,
                default => ColorPreference::RANDOM,
            };

            $game = $this->gameFactory->createAiOrHotseatGame($user, $data['opponentType'], $colorPreference);

            $this->entityManager->persist($game);
            $this->entityManager->flush();

            return $this->redirectToRoute('play', ['uuid' => $game->getUuid()]);
        }

        return ['form' => $form->createView()];
    }
}
