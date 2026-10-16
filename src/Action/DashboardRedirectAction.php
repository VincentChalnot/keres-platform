<?php

declare(strict_types=1);

namespace App\Action;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * `GET /dashboard` - the dashboard became the home page `/`; a permanent redirect keeps the bookmarks working.
 */
#[AsController]
readonly class DashboardRedirectAction
{
    public function __construct(private RouterInterface $router)
    {
    }

    #[Route(path: '/dashboard', name: 'dashboard_redirect', methods: ['GET'])]
    public function __invoke(): RedirectResponse
    {
        return new RedirectResponse($this->router->generate('index'), RedirectResponse::HTTP_MOVED_PERMANENTLY);
    }
}
