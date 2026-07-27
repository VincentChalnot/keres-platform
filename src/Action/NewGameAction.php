<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Form\NewGameType;
use App\Model\PieceColor;
use App\Repository\GameRepository;
use App\Service\GameFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
class NewGameAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GameRepository $gameRepository,
        private readonly GameFactory $gameFactory,
    ) {
    }

    #[Route(
        path: '/play',
        name: 'new_game',
        methods: ['GET', 'POST'],
    )]
    public function __(Request $request): RedirectResponse|array
    {
        $form = $this->createForm(NewGameType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $user = $this->getUser();

            if (!$user instanceof User) {
                throw $this->createAccessDeniedException('User is required to create a game');
            }

            $creatorColor = match ($data['playerSide']) {
                'white' => PieceColor::WHITE,
                'black' => PieceColor::BLACK,
                'random' => 0 === random_int(0, 1) ? PieceColor::WHITE : PieceColor::BLACK,
            };

            $game = $this->gameFactory->createAiOrHotseatGame($user, $data['opponentType'], $creatorColor);

            $this->entityManager->persist($game);
            $this->entityManager->flush();

            return $this->redirectToRoute('play', ['uuid' => $game->getUuid()]);
        }

        $user = $this->getUser();

        if ($user instanceof User) {
            $inProgressGames = $this->gameRepository->findOngoingForUser($user);
            $finishedGames = $this->gameRepository->findFinishedForUser($user);
        } else {
            $inProgressGames = [];
            $finishedGames = [];
        }

        return [
            'form' => $form->createView(),
            'inProgressGames' => $inProgressGames,
            'finishedGames' => $finishedGames,
        ];
    }
}
