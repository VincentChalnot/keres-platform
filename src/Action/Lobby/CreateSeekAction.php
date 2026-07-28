<?php

declare(strict_types=1);

namespace App\Action\Lobby;

use App\Entity\User;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Model\ColorPreference;
use App\Model\Request\SeekCreateRequest;
use App\Model\TimeControl;
use App\Model\TimeControlKind;
use App\Service\Matchmaking\SeekCreationService;
use App\Service\Matchmaking\SeekPayloadBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * `POST /lobby/seeks` - the custom-seek front door (04-matchmaking.md sec
 * 1.1/9.2). Every real seek row, whether from here or `QuickPairAction`,
 * is built by `SeekCreationService`.
 */
#[AsController]
readonly class CreateSeekAction
{
    public function __construct(
        private Security $security,
        private ValidatorInterface $validator,
        private SeekCreationService $seekCreationService,
        private SeekPayloadBuilder $seekPayloadBuilder,
        private ClockInterface $clock,
        private RateLimiterFactory $seekCreateLimiter,
    ) {
    }

    #[Route(path: '/lobby/seeks', name: 'lobby_seek_create', methods: ['POST'])]
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

        $timeControl = $this->resolveTimeControl($seekRequest);

        if ($timeControl instanceof JsonResponse) {
            return $timeControl;
        }

        if ($seekRequest->rated && TimeControlKind::UNLIMITED === $timeControl->getKind()) {
            return ApiResponse::error(ApiErrorCode::UNRATED_TIME_CONTROL, '"unlimited" games cannot be rated.');
        }

        if ($seekRequest->autoWiden && (null !== $seekRequest->ratingMin || null !== $seekRequest->ratingMax)) {
            return ApiResponse::error(ApiErrorCode::VALIDATION_FAILED, 'autoWiden is mutually exclusive with an explicit rating window.', [
                'violations' => [['field' => 'autoWiden', 'constraint' => 'mutually_exclusive', 'message' => 'Cannot combine autoWiden with ratingMin/ratingMax.']],
            ]);
        }

        if (null !== $seekRequest->ratingMin && null !== $seekRequest->ratingMax && $seekRequest->ratingMin > $seekRequest->ratingMax) {
            return ApiResponse::error(ApiErrorCode::VALIDATION_FAILED, 'ratingMin must be <= ratingMax.', [
                'violations' => [['field' => 'ratingMin', 'constraint' => 'range_ordered', 'message' => 'ratingMin must be less than or equal to ratingMax.']],
            ]);
        }

        $colorPreference = match ($seekRequest->colorPreference) {
            'white' => ColorPreference::WHITE,
            'black' => ColorPreference::BLACK,
            default => ColorPreference::RANDOM,
        };

        $outcome = $this->seekCreationService->create(
            $user,
            $timeControl,
            $seekRequest->rated,
            $colorPreference,
            $seekRequest->autoWiden,
            $seekRequest->ratingMin,
            $seekRequest->ratingMax,
        );

        return ApiResponse::ok([
            'seek' => $this->seekPayloadBuilder->buildSummary($outcome->seek, $user, $this->clock->now()),
            'matched' => null !== $outcome->matchedGame ? ['gameUuid' => $outcome->matchedGame->getUuid()->toRfc4122()] : null,
            'deduped' => $outcome->deduped,
        ]);
    }

    /** @return JsonResponse|TimeControl the built value, or a 422 `invalid_time_control` response */
    private function resolveTimeControl(SeekCreateRequest $r): TimeControl|JsonResponse
    {
        $reason = match ($r->kind) {
            'unlimited' => (null !== $r->initialSeconds || null !== $r->incrementSeconds || null !== $r->daysPerMove)
                ? 'unlimited carries a time-control field' : null,
            'realtime' => match (true) {
                null === $r->initialSeconds || null === $r->incrementSeconds => 'realtime requires initialSeconds and incrementSeconds',
                null !== $r->daysPerMove => 'realtime carries daysPerMove',
                default => null,
            },
            'correspondence' => match (true) {
                null === $r->daysPerMove => 'correspondence requires daysPerMove',
                null !== $r->initialSeconds || null !== $r->incrementSeconds => 'correspondence carries a realtime field',
                default => null,
            },
            default => 'unknown kind',
        };

        if (null !== $reason) {
            return ApiResponse::error(ApiErrorCode::INVALID_TIME_CONTROL, 'Time-control fields are incoherent with kind.', ['reason' => $reason]);
        }

        return match ($r->kind) {
            'unlimited' => TimeControl::unlimited(),
            'realtime' => TimeControl::realtime($r->initialSeconds, $r->incrementSeconds),
            default => TimeControl::correspondence($r->daysPerMove),
        };
    }
}
