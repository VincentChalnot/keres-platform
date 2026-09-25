<?php

declare(strict_types=1);

namespace App\Action\Settings;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

/** `GET /settings` - the header's single settings entry point opens the first section. */
#[AsController]
readonly class SettingsIndexAction
{
    public function __construct(
        private RouterInterface $router,
    ) {
    }

    #[Route(path: '/settings', name: 'settings', methods: ['GET'])]
    public function __invoke(): RedirectResponse
    {
        return new RedirectResponse($this->router->generate('settings_profile'));
    }
}
