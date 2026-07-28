<?php

declare(strict_types=1);

namespace App\Action\Lobby;

use App\Entity\User;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Repository\SeekRepository;
use App\Service\Matchmaking\SeekPayloadBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /lobby/seeks` (04-matchmaking.md sec 5.1) - anonymous, public,
 * IP-rate-limited. The listing predicate is the pairing predicate minus
 * viewer-specific clauses: a seek that cannot be paired must not be shown.
 */
#[AsController]
readonly class LobbySeekListAction
{
    public function __construct(
        private Security $security,
        private SeekRepository $seekRepository,
        private SeekPayloadBuilder $seekPayloadBuilder,
        private ClockInterface $clock,
        private RateLimiterFactory $lobbyReadLimiter,
    ) {
    }

    #[Route(path: '/lobby/seeks', name: 'lobby_seek_list', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->lobbyReadLimiter->create($request->getClientIp() ?? 'unknown')->consume(1)->isAccepted()) {
            return ApiResponse::error(ApiErrorCode::RATE_LIMITED, 'Too many requests.');
        }

        $user = $this->security->getUser();
        $viewer = $user instanceof User ? $user : null;
        $now = $this->clock->now();

        $seeks = $this->seekRepository->findOpenForListing($now);

        return ApiResponse::ok($this->seekPayloadBuilder->buildListing($seeks, $viewer, \count($seeks), $now));
    }
}
