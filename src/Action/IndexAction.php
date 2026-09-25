<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * `GET /`: signed-in players land on their dashboard; anonymous visitors on
 * the lobby, which greets them with the sign-in prompt.
 */
#[AsController]
readonly class IndexAction
{
    public function __construct(
        private RouterInterface $router,
        private Security $security,
    ) {
    }

    #[Route(
        path: '/',
        name: 'index',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): RedirectResponse
    {
        $route = $this->security->getUser() instanceof User ? 'dashboard' : 'lobby';

        return new RedirectResponse($this->router->generate($route));
    }
}
