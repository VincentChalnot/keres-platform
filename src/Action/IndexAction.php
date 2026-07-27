<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\Feedback;
use App\Entity\User;
use App\Form\FeedbackType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
readonly class IndexAction
{
    public function __construct(
        private RouterInterface $router,
    ) {
    }

    #[Route(
        path: '/',
        name: 'index',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): RedirectResponse
    {
        return new RedirectResponse($this->router->generate('new_game'));
    }
}
