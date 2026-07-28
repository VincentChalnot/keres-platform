<?php

declare(strict_types=1);

namespace App\Action\Lobby;

use App\Entity\Game;
use App\Entity\User;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Repository\SeekRepository;
use App\Service\Game\GameUpdatePublisher;
use App\Service\Matchmaking\SeekPayloadBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /lobby/seeks/{uuid}/cancel` (04-matchmaking.md sec 2/5, sec 7 race
 * 2/3). Also the `sendBeacon` target on `beforeunload` (sec 4.1) - no CSRF
 * token is required for a `sendBeacon` POST, so this route relies on the
 * SameSite=Lax cookie posture like every other `/lobby/*` mutation
 * (09-api-reference.md sec 9.3).
 */
#[AsController]
readonly class CancelSeekAction
{
    public function __construct(
        private Security $security,
        private SeekRepository $seekRepository,
        private EntityManagerInterface $entityManager,
        private GameUpdatePublisher $publisher,
        private SeekPayloadBuilder $seekPayloadBuilder,
        private ClockInterface $clock,
    ) {
    }

    #[Route(path: '/lobby/seeks/{uuid}/cancel', name: 'lobby_seek_cancel', methods: ['POST'], requirements: ['uuid' => Requirement::UUID])]
    public function __invoke(string $uuid): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return ApiResponse::error(ApiErrorCode::AUTHENTICATION_REQUIRED, 'Authentication required.');
        }

        $seek = $this->seekRepository->findByUuid(Uuid::fromString($uuid));

        if (null === $seek) {
            return ApiResponse::error(ApiErrorCode::SEEK_NOT_FOUND, 'Seek not found.');
        }

        if ($seek->getUser() !== $user) {
            return ApiResponse::error(ApiErrorCode::FORBIDDEN, 'Not your seek.');
        }

        $now = $this->clock->now();

        // Lock-ordered cancel (sec 7 race 2/3): a concurrent matcher may
        // already hold this row's terminal write.
        $affected = $this->entityManager->getConnection()->executeStatement(
            'UPDATE seek SET status_value = 2 WHERE id = :id AND status_value = 0',
            ['id' => $seek->getId()],
        );

        if (0 === $affected) {
            $row = $this->entityManager->getConnection()->fetchAssociative(
                'SELECT status_value, matched_game_id FROM seek WHERE id = :id',
                ['id' => $seek->getId()],
            );

            if (false !== $row && 1 === (int) $row['status_value'] && null !== $row['matched_game_id']) {
                $game = $this->entityManager->getRepository(Game::class)->find((int) $row['matched_game_id']);

                return ApiResponse::error(ApiErrorCode::SEEK_ALREADY_MATCHED, 'This seek was already matched.', [
                    'gameUuid' => $game?->getUuid()->toRfc4122(),
                ]);
            }

            // Already CANCELED/EXPIRED: idempotent no-op success.
            return ApiResponse::ok(['seek' => ['uuid' => $seek->getUuid()->toRfc4122(), 'status' => 'canceled']]);
        }

        $poolSize = \count($this->seekRepository->findOpenForListing($now));
        $event = $this->seekPayloadBuilder->buildRemovedEvent($seek->getUuid(), 'canceled', $poolSize, $now);
        $this->publisher->publishSeekEvent($this->seekPayloadBuilder->encode($event));

        return ApiResponse::ok(['seek' => ['uuid' => $seek->getUuid()->toRfc4122(), 'status' => 'canceled']]);
    }
}
