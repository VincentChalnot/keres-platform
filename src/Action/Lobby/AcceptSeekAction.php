<?php

declare(strict_types=1);

namespace App\Action\Lobby;

use App\Entity\User;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Model\ColorPreference;
use App\Model\MultiplayerLimits;
use App\Repository\SeekRepository;
use App\Service\Matchmaking\SeekCreationService;
use App\Service\Matchmaking\SeekMatcher;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /lobby/seeks/{uuid}/accept` - not a fourth mechanism
 * (04-matchmaking.md sec 5.3): builds a mirror seek for the clicker through
 * the same `SeekCreationService::insertOrReplaceSeek()` write path as every
 * other seek (sec 6.2's one-open-seek-per-user invariant applies to the
 * clicker too), then runs the identical pairing transaction narrowed to
 * the one target row.
 *
 * If narrowed pairing fails (the target matched/canceled/expired
 * microseconds earlier), a *freshly inserted* mirror is explicitly
 * canceled rather than left `OPEN` - `SeekMatcher::attemptPair()` commits
 * its own transaction per attempt (sec 3.5), so "insert, then
 * pair-or-cancel" is two statements, not one atomic unit. A *deduped*
 * mirror (the clicker already had this exact tuple open) is left alone on
 * failure: it is the clicker's own real, pre-existing seek, not a
 * throwaway - canceling it would destroy intent the accept click did not
 * express.
 */
#[AsController]
readonly class AcceptSeekAction
{
    public function __construct(
        private Security $security,
        private SeekRepository $seekRepository,
        private SeekCreationService $seekCreationService,
        private SeekMatcher $seekMatcher,
        private ClockInterface $clock,
        private RateLimiterFactory $seekAcceptLimiter,
    ) {
    }

    #[Route(path: '/lobby/seeks/{uuid}/accept', name: 'lobby_seek_accept', methods: ['POST'], requirements: ['uuid' => Requirement::UUID])]
    public function __invoke(string $uuid): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return ApiResponse::error(ApiErrorCode::AUTHENTICATION_REQUIRED, 'Authentication required.');
        }

        if (!$this->seekAcceptLimiter->create((string) $user->getId())->consume(1)->isAccepted()) {
            return ApiResponse::error(ApiErrorCode::RATE_LIMITED, 'Too many accepts recently.');
        }

        $target = $this->seekRepository->findByUuid(Uuid::fromString($uuid));

        if (null === $target) {
            return ApiResponse::error(ApiErrorCode::SEEK_NOT_FOUND, 'Seek not found.');
        }

        if ($target->getUser() === $user) {
            return ApiResponse::error(ApiErrorCode::CANNOT_ACCEPT_OWN_SEEK, 'You cannot accept your own seek.');
        }

        $now = $this->clock->now();

        if ($target->isExpired($now)) {
            return ApiResponse::error(ApiErrorCode::SEEK_EXPIRED, 'This seek has expired.');
        }

        if (!$target->isOpen()) {
            return ApiResponse::error(ApiErrorCode::SEEK_UNAVAILABLE, 'This seek is no longer open.');
        }

        // sec 3.2/5.1 "playable": ignoring the clicker's own window - clicking is consent.
        $viewerRating = MultiplayerLimits::GLICKO_DEFAULT_RATING;

        if (!$target->isAutoWiden()) {
            $outOfRange = (null !== $target->getRatingMin() && $viewerRating < $target->getRatingMin())
                || (null !== $target->getRatingMax() && $viewerRating > $target->getRatingMax());

            if ($outOfRange) {
                return ApiResponse::error(ApiErrorCode::RATING_OUT_OF_RANGE, 'Your rating is outside this seek\'s window.');
            }
        }

        $mirrorColor = ColorPreference::RANDOM === $target->getColorPreference()
            ? ColorPreference::RANDOM
            : (ColorPreference::WHITE === $target->getColorPreference() ? ColorPreference::BLACK : ColorPreference::WHITE);

        $result = $this->seekCreationService->insertOrReplaceSeek(
            $user,
            $target->getTimeControl(),
            $target->isRated(),
            $mirrorColor,
            false,
            null,
            null,
        );

        $game = $this->seekMatcher->attemptPair((int) $result->seek->getId(), $target->getUuid());

        if (null === $game) {
            if (!$result->deduped) {
                $this->seekCreationService->cancelSeek($result->seek, 'canceled');
            }

            return ApiResponse::error(ApiErrorCode::SEEK_UNAVAILABLE, 'This seek is no longer available.');
        }

        return ApiResponse::ok(['gameUuid' => $game->getUuid()->toRfc4122()]);
    }
}
