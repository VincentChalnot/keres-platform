<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Model\ColorPreference;
use App\Repository\FriendshipRepository;
use App\Repository\SeekRepository;
use App\Service\Analytics\AnalyticsRecorder;
use App\Service\Matchmaking\SeekCreationService;
use App\Service\Matchmaking\SeekMatcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * T11: `GET /invite/{uuid}` - the "Invite a friend" landing page. `uuid` is
 * the invite `Seek`'s own id: an unguessable v4 UUID already doubles as the
 * shareable link's token (see `Seek`'s docblock), so there is no separate
 * token/hash to manage. `ROLE_USER`-gated via `access_control`
 * (`^/invite`), which is what makes the login-redirect-and-come-back flow
 * free: Symfony's `main` firewall entry point
 * (`MultiProviderOidcAuthenticator::start()`) already saves this exact URL
 * as `target_path` before sending an anonymous visitor to log in, and both
 * authenticators restore it on success - no new redirect plumbing needed.
 *
 * Mirrors `AcceptSeekAction`'s checks/flow (self-accept, blocked, expired,
 * open) adapted for a GET+redirect instead of POST+JSON, narrowed pairing
 * via the exact same `SeekMatcher::attemptPair($mirrorId, $target->getUuid())`
 * path. Single-use falls out of `Seek`'s own OPEN -> MATCHED transition
 * (`SeekMatcher`'s terminal `UPDATE ... WHERE status_value = 0`): a second
 * open of the same link finds `isOpen() === false` and shows the same
 * "invalid or expired" state, exactly as `WaitlistConfirmAction` (T7)
 * rejects an already-confirmed token - same idempotent-landing-page shape,
 * Seek's richer state machine standing in for `confirmedAt`.
 */
#[AsController]
class InviteAcceptAction extends AbstractController
{
    public function __construct(
        private readonly SeekRepository $seekRepository,
        private readonly SeekCreationService $seekCreationService,
        private readonly SeekMatcher $seekMatcher,
        private readonly FriendshipRepository $friendshipRepository,
        private readonly ClockInterface $clock,
        private readonly RateLimiterFactory $seekAcceptLimiter,
        private readonly AnalyticsRecorder $analyticsRecorder,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/invite/{uuid}', name: 'invite_accept', methods: ['GET'], requirements: ['uuid' => Requirement::UUID])]
    public function __invoke(string $uuid): array|RedirectResponse
    {
        /** @var User $user ROLE_USER-gated, always authenticated here */
        $user = $this->getUser();

        $target = $this->seekRepository->findByUuid(Uuid::fromString($uuid));

        if (null === $target || !$target->isInviteOnly()) {
            return ['state' => 'not_found'];
        }

        if ($target->getUser() === $user) {
            return ['state' => 'self_invite'];
        }

        if ($this->friendshipRepository->isBlockedEitherWay($user, $target->getUser())) {
            return ['state' => 'blocked'];
        }

        $now = $this->clock->now();

        if ($target->isExpired($now) || !$target->isOpen()) {
            return ['state' => 'expired'];
        }

        if (!$this->seekAcceptLimiter->create((string) $user->getId())->consume(1)->isAccepted()) {
            return ['state' => 'rate_limited'];
        }

        $mirrorColor = match ($target->getColorPreference()) {
            ColorPreference::WHITE => ColorPreference::BLACK,
            ColorPreference::BLACK => ColorPreference::WHITE,
            ColorPreference::RANDOM => ColorPreference::RANDOM,
        };

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

            return ['state' => 'expired'];
        }

        $this->analyticsRecorder->inviteAccepted($user, $target, $game);

        return $this->redirectToRoute('play', ['uuid' => $game->getUuid()]);
    }
}
