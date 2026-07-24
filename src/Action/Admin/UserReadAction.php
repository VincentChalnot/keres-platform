<?php

declare(strict_types=1);

namespace App\Action\Admin;

use App\Repository\AdminStatsRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Custom read action for the User admin: the User entity has no editable
 * admin fields (accounts come from OIDC/dev-login/email-signup, role
 * management is CLI-only), so this renders a bespoke per-user detail view
 * with aggregate game statistics and a per-game breakdown instead of the
 * generic Sidus\AdminBundle read/form chrome.
 */
#[AsController]
class UserReadAction extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AdminStatsRepository $adminStatsRepository,
    ) {
    }

    public function __invoke(string $id): array
    {
        $user = $this->userRepository->find($id);

        if (null === $user) {
            throw $this->createNotFoundException('User not found.');
        }

        return [
            'user' => $user,
            'stats' => $this->adminStatsRepository->getUserStats($user),
            'games' => $this->adminStatsRepository->getUserGames($user),
        ];
    }
}
