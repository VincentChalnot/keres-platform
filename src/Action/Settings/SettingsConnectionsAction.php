<?php

declare(strict_types=1);

namespace App\Action\Settings;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** `GET /settings/connections` - Settings -> Connected accounts: the linked sign-in providers (read-only). */
#[AsController]
class SettingsConnectionsAction extends AbstractController
{
    #[IsGranted('ROLE_USER')]
    #[Route(path: '/settings/connections', name: 'settings_connections', methods: ['GET'])]
    public function __invoke(): array
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is required to view settings.');
        }

        return [
            'section' => 'connections',
            'user' => $user,
        ];
    }
}
