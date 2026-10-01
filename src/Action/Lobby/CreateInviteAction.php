<?php

declare(strict_types=1);

namespace App\Action\Lobby;

use App\Entity\User;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Model\ColorPreference;
use App\Model\Request\SeekCreateRequest;
use App\Model\TimeControlKind;
use App\Service\Analytics\AnalyticsRecorder;
use App\Service\Matchmaking\SeekCreationService;
use App\Service\Matchmaking\TimeControlRequestResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * T11: `POST /lobby/invites` - "Invite a friend". Reuses the lobby's own
 * "New seek" form fields (`SeekCreateRequest`, the same shape and the same
 * `TimeControlRequestResolver` validation `CreateSeekAction` uses) but
 * writes an invite-only `Seek` instead of a public one:
 * `SeekCreationService::insertOrReplaceSeek()` (the insert/dedupe/replace
 * write path only - never `create()`, which would immediately attempt to
 * pair the inviter against the whole public pool, defeating the point of
 * inviting one specific person) with `inviteOnly: true`. No rating window,
 * no auto-widen - meaningless for a token-gated 1:1 invite. The response
 * carries the shareable `/invite/{uuid}` URL for the caller to copy.
 */
#[AsController]
readonly class CreateInviteAction
{
    public function __construct(
        private Security $security,
        private ValidatorInterface $validator,
        private SeekCreationService $seekCreationService,
        private TimeControlRequestResolver $timeControlRequestResolver,
        private UrlGeneratorInterface $urlGenerator,
        private RateLimiterFactory $seekCreateLimiter,
        private AnalyticsRecorder $analyticsRecorder,
    ) {
    }

    #[Route(path: '/lobby/invites', name: 'lobby_invite_create', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return ApiResponse::error(ApiErrorCode::AUTHENTICATION_REQUIRED, 'Authentication required.');
        }

        if (!$this->seekCreateLimiter->create((string) $user->getId())->consume(1)->isAccepted()) {
            return ApiResponse::error(ApiErrorCode::RATE_LIMITED, 'Too many seeks created recently.');
        }

        $data = json_decode($request->getContent(), true);

        if (!\is_array($data)) {
            return ApiResponse::error(ApiErrorCode::MALFORMED_JSON, 'Request body is not valid JSON.');
        }

        $seekRequest = SeekCreateRequest::fromArray($data);
        $violations = $this->validator->validate($seekRequest);

        if (\count($violations) > 0) {
            return ApiResponse::validation($violations);
        }

        $timeControl = $this->timeControlRequestResolver->resolve($seekRequest);

        if (\is_string($timeControl)) {
            return ApiResponse::error(ApiErrorCode::INVALID_TIME_CONTROL, 'Time-control fields are incoherent with kind.', ['reason' => $timeControl]);
        }

        if ($seekRequest->rated && TimeControlKind::UNLIMITED === $timeControl->getKind()) {
            return ApiResponse::error(ApiErrorCode::UNRATED_TIME_CONTROL, '"unlimited" games cannot be rated.');
        }

        $colorPreference = match ($seekRequest->colorPreference) {
            'white' => ColorPreference::WHITE,
            'black' => ColorPreference::BLACK,
            default => ColorPreference::RANDOM,
        };

        $result = $this->seekCreationService->insertOrReplaceSeek(
            $user,
            $timeControl,
            $seekRequest->rated,
            $colorPreference,
            false,
            null,
            null,
            true,
        );

        $this->analyticsRecorder->inviteSent($user, $result->seek);

        $url = $this->urlGenerator->generate('invite_accept', ['uuid' => $result->seek->getUuid()->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL);

        return ApiResponse::ok(['url' => $url]);
    }
}
