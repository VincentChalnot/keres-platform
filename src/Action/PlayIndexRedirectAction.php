<?php

declare(strict_types=1);

namespace App\Action;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * `GET /play` (04-matchmaking.md sec 9.2) - `new_game` disappears with this
 * bare path; kept as a 302 to `/lobby` for bookmarks, nothing more.
 */
#[AsController]
readonly class PlayIndexRedirectAction
{
    public function __construct(
        private RouterInterface $router,
    ) {
    }

    #[Route(path: '/play', name: 'play_index_redirect', methods: ['GET'])]
    public function __invoke(): RedirectResponse
    {
        return new RedirectResponse($this->router->generate('lobby'));
    }
}
