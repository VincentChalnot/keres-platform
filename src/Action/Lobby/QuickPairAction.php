<?php

declare(strict_types=1);

namespace App\Action\Lobby;

use App\Entity\User;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Model\ColorPreference;
use App\Model\TimeControl;
use App\Service\Matchmaking\SeekCreationService;
use App\Service\Matchmaking\SeekPayloadBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /lobby/seeks/quick` - exactly `POST /lobby/seeks` with `autoWiden`
 * forced true (REALTIME only), `colorPreference=random`, no explicit
 * window, and the time-control tuple looked up from the preset table
 * (04-matchmaking.md sec 1.1, sec 5), never taken from the client.
 */
#[AsController]
readonly class QuickPairAction
{
    /** [initialSeconds, incrementSeconds] | ['days' => int] per preset (sec 1.1). */
    private const array PRESETS = [
        '1+0' => [60, 0],
        '3+2' => [180, 2],
        '5+0' => [300, 0],
        '10+0' => [600, 0],
        '15+10' => [900, 10],
        'corr1' => ['days' => 1],
        'corr3' => ['days' => 3],
        'corr7' => ['days' => 7],
    ];

    public function __construct(
        private Security $security,
        private SeekCreationService $seekCreationService,
        private SeekPayloadBuilder $seekPayloadBuilder,
        private ClockInterface $clock,
        private RateLimiterFactory $seekCreateLimiter,
    ) {
    }

    #[Route(path: '/lobby/seeks/quick', name: 'lobby_seek_quick_pair', methods: ['POST'])]
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
        $preset = \is_array($data) && \is_string($data['preset'] ?? null) ? $data['preset'] : null;

        if (null === $preset || !isset(self::PRESETS[$preset])) {
            return ApiResponse::error(ApiErrorCode::VALIDATION_FAILED, 'Unknown quick-pair preset.', [
                'violations' => [['field' => 'preset', 'constraint' => 'choice', 'message' => 'The value you selected is not a valid choice.']],
            ]);
        }

        $spec = self::PRESETS[$preset];
        $isCorrespondence = \array_key_exists('days', $spec);
        $timeControl = $isCorrespondence ? TimeControl::correspondence($spec['days']) : TimeControl::realtime($spec[0], $spec[1]);

        $outcome = $this->seekCreationService->create(
            $user,
            $timeControl,
            true, // quick pair is always rated (never UNLIMITED)
            ColorPreference::RANDOM,
            !$isCorrespondence, // autoWiden: REALTIME only (sec 1.3 - CORRESPONDENCE never widens)
            null,
            null,
        );

        return ApiResponse::ok([
            'seek' => $this->seekPayloadBuilder->buildSummary($outcome->seek, $user, $this->clock->now()),
            'matched' => null !== $outcome->matchedGame ? ['gameUuid' => $outcome->matchedGame->getUuid()->toRfc4122()] : null,
            'deduped' => $outcome->deduped,
        ]);
    }
}
