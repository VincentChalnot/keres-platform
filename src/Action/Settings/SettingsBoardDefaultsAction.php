<?php

declare(strict_types=1);

namespace App\Action\Settings;

use App\Entity\User;
use App\Http\ApiResponse;
use App\Model\ApiErrorCode;
use App\Service\UserPreferencesManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /settings/board/defaults` - the game page's "Save as defaults" button:
 * persists the in-game board toggles as the player's Board & gameplay settings.
 */
#[AsController]
readonly class SettingsBoardDefaultsAction
{
    private const array FIELDS = ['showCoordinates', 'showThreats', 'rotateOpponentPieces'];

    public function __construct(
        private Security $security,
        private UserPreferencesManager $userPreferencesManager,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/settings/board/defaults', name: 'settings_board_defaults', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return ApiResponse::error(ApiErrorCode::AUTHENTICATION_REQUIRED, 'Authentication required.');
        }

        $data = json_decode($request->getContent(), true);

        if (!\is_array($data)) {
            return ApiResponse::error(ApiErrorCode::MALFORMED_JSON, 'Request body is not valid JSON.');
        }

        $violations = [];

        foreach (self::FIELDS as $field) {
            if (!\is_bool($data[$field] ?? null)) {
                $violations[] = ['field' => $field, 'constraint' => 'type', 'message' => 'This value should be a boolean.'];
            }
        }

        if ([] !== $violations) {
            return ApiResponse::error(ApiErrorCode::VALIDATION_FAILED, 'Request payload failed validation.', ['violations' => $violations]);
        }

        $preferences = $this->userPreferencesManager->getOrCreate($user);
        $preferences->setShowBoardCoordinates($data['showCoordinates']);
        $preferences->setShowOpponentThreatsOnHover($data['showThreats']);
        $preferences->setRotateOpponentPieces($data['rotateOpponentPieces']);
        $preferences->touch();
        $this->entityManager->flush();

        return ApiResponse::ok([
            'showCoordinates' => $data['showCoordinates'],
            'showThreats' => $data['showThreats'],
            'rotateOpponentPieces' => $data['rotateOpponentPieces'],
        ]);
    }
}
