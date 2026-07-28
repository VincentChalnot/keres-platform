<?php

declare(strict_types=1);

namespace App\Action\Lobby;

use App\Entity\User;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Repository\SeekRepository;
use App\Service\Matchmaking\SeekMatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /lobby/seeks/{uuid}/heartbeat` - the widening retry clock
 * (04-matchmaking.md sec 3.1/4.2). Because pairing runs on every heartbeat,
 * this is a legitimate place to learn a seek got matched, not only over SSE.
 */
#[AsController]
readonly class HeartbeatSeekAction
{
    public function __construct(
        private Security $security,
        private SeekRepository $seekRepository,
        private EntityManagerInterface $entityManager,
        private SeekMatcher $seekMatcher,
        private ClockInterface $clock,
        private RateLimiterFactory $seekHeartbeatLimiter,
    ) {
    }

    #[Route(path: '/lobby/seeks/{uuid}/heartbeat', name: 'lobby_seek_heartbeat', methods: ['POST'], requirements: ['uuid' => Requirement::UUID])]
    public function __invoke(string $uuid): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return ApiResponse::error(ApiErrorCode::AUTHENTICATION_REQUIRED, 'Authentication required.');
        }

        if (!$this->seekHeartbeatLimiter->create($uuid)->consume(1)->isAccepted()) {
            return ApiResponse::error(ApiErrorCode::RATE_LIMITED, 'Too many heartbeats.');
        }

        $seek = $this->seekRepository->findByUuid(Uuid::fromString($uuid));

        if (null === $seek) {
            return ApiResponse::error(ApiErrorCode::SEEK_NOT_FOUND, 'Seek not found.');
        }

        if ($seek->getUser() !== $user) {
            return ApiResponse::error(ApiErrorCode::FORBIDDEN, 'Not your seek.');
        }

        $now = $this->clock->now();

        if ($seek->isExpired($now)) {
            return ApiResponse::error(ApiErrorCode::SEEK_EXPIRED, 'This seek has expired.');
        }

        if (!$seek->isOpen()) {
            $matchedGame = $seek->getMatchedGame();

            if (null !== $matchedGame) {
                return ApiResponse::ok(['status' => 'matched', 'gameUuid' => $matchedGame->getUuid()->toRfc4122(), 'widenedTo' => null]);
            }

            return ApiResponse::error(ApiErrorCode::SEEK_UNAVAILABLE, 'This seek is no longer open.');
        }

        // Step 1: UPDATE ... last_heartbeat_at = now() WHERE status = OPEN.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE seek SET last_heartbeat_at = :now WHERE id = :id AND status_value = 0',
            ['now' => $now->format('Y-m-d H:i:s.uP'), 'id' => $seek->getId()],
        );

        // Step 2: attemptPair - the widening retry (sec 3.1).
        $game = $this->seekMatcher->attemptPair((int) $seek->getId());

        if (null !== $game) {
            return ApiResponse::ok(['status' => 'matched', 'gameUuid' => $game->getUuid()->toRfc4122(), 'widenedTo' => null]);
        }

        return ApiResponse::ok(['status' => 'open', 'gameUuid' => null, 'widenedTo' => $seek->widenedWindow($now)]);
    }
}
