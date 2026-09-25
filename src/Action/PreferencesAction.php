<?php

declare(strict_types=1);

namespace App\Action;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * `GET /preferences` - the old "Your preferences" page, merged into
 * Settings. Kept as a permanent redirect for bookmarks: its email, name,
 * language and country now live under Settings -> Profile.
 */
#[AsController]
readonly class PreferencesAction
{
    public function __construct(
        private RouterInterface $router,
    ) {
    }

    #[Route(path: '/preferences', name: 'preferences', methods: ['GET'])]
    public function __invoke(): RedirectResponse
    {
        return new RedirectResponse($this->router->generate('settings_profile'), Response::HTTP_MOVED_PERMANENTLY);
    }
}
