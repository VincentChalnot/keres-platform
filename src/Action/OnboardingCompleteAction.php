<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Service\Onboarding\OnboardingCookie;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /onboarding/complete`: the tutorial was finished or its invitation dismissed. Remembers it in this browser
 * (cookie) and, for a signed-in user, on the account. Public and without CSRF: the only effect is hiding a banner.
 */
#[AsController]
class OnboardingCompleteAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/onboarding/complete', name: 'onboarding_complete', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();

        if ($user instanceof User) {
            $user->markOnboarded(new \DateTimeImmutable());
            $this->entityManager->flush();
        }

        $response = new Response(status: Response::HTTP_NO_CONTENT);
        $response->headers->setCookie(OnboardingCookie::create($request->isSecure()));

        return $response;
    }
}
