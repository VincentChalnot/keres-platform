<?php

declare(strict_types=1);

namespace App\Action\Api;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/** `POST /api/game-over-reason` - relays the engine's endpoint (guest games have no `Game` row to store the code on). */
#[AsController]
readonly class GameOverReasonAction extends AbstractForwardToApiAction
{
    #[Route(
        path: '/api/game-over-reason',
        name: 'api_game_over_reason',
        methods: ['POST'],
    )]
    public function __(Request $request): Response
    {
        return $this->forward($request);
    }
}
