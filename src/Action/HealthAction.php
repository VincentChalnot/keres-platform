<?php

declare(strict_types=1);

namespace App\Action;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /health`: liveness probe for uptime monitors. Deliberately touches
 * nothing (no database, session, security user or template) so it stays
 * cheap and never creates a session cookie. No `access_control` rule
 * matches it, so it is reachable anonymously.
 */
#[AsController]
readonly class HealthAction
{
    #[Route(path: '/health', name: 'health', methods: ['GET'])]
    public function __invoke(): Response
    {
        $response = new Response('ok', Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->addCacheControlDirective('no-cache');
        $response->headers->addCacheControlDirective('private');

        return $response;
    }
}
