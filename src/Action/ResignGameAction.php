<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\Game;
use App\Entity\User;
use App\Repository\GameRepository;
use App\Security\Voter\GameVoter;
use App\Service\Game\ClockManager;
use App\Service\Game\GameLifecycleManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
class ResignGameAction extends AbstractController
{
    public function __construct(
        private readonly GameRepository $gameRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly GameLifecycleManager $gameLifecycleManager,
        private readonly ClockManager $clockManager,
    ) {
    }

    #[Route(
        path: '/play/{uuid}/resign',
        name: 'resign_game',
        methods: ['POST'],
    )]
    public function __(string $uuid): Response
    {
        $game = $this->gameRepository->findByUuid(Uuid::fromString($uuid));

        if (!$game) {
            throw $this->createNotFoundException('Game not found');
        }

        $this->denyAccessUnlessGranted(GameVoter::PARTICIPATE, $game);

        if ($game->isGameOver()) {
            return $this->redirectToRoute('lobby');
        }

        $user = $this->getUser();
        $colors = $game->getColorsForUser($user instanceof User ? $user : null);
        // Hot-seat holds both colours for the same user - ambiguous who
        // resigned, so fall back to the creator's colour, matching this
        // action's pre-multiplayer behaviour exactly. Every other mode has
        // exactly one acting colour.
        $resignerColor = 1 === \count($colors) ? $colors[0] : $game->getCreatorColor();

        // Transaction + row lock (matching AbortGameAction/ClockAdjudicator):
        // RatingUpdater::applyForFinishedGame() must run inside the same
        // transaction that writes gameOverAt (06-rating.md sec 9.3), and a
        // bare persist()/flush() here raced a concurrent finaliser (a
        // second resign click, or a flag fall landing at the same instant)
        // into an uncaught OptimisticLockException instead of a clean no-op.
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($game, $resignerColor): void {
            $em->getConnection()->executeStatement("SET LOCAL lock_timeout = '3s'");
            $em->find(Game::class, $game->getId(), LockMode::PESSIMISTIC_WRITE);

            if ($game->isGameOver()) {
                return;
            }

            $this->clockManager->stop($game, $this->clockManager->nowMicros());
            $this->gameLifecycleManager->resign($game, $resignerColor);
            $em->flush();
        });

        return $this->redirectToRoute('lobby');
    }
}
