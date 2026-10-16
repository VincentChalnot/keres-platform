<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Model\ChessExperience;
use App\Service\Onboarding\ChessExperienceCookie;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /onboarding/experience` (`experience` = player | beginner | skipped): the answer to "Do you play chess?".
 * Stored on the account when signed in and always in this browser's cookie (the one anonymous visitors are known by,
 * copied to the account at sign-up). Public and without CSRF, like `/onboarding/complete`: the only effect is which
 * tutorial track is offered.
 */
#[AsController]
class OnboardingExperienceAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/onboarding/experience', name: 'onboarding_experience', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $experience = ChessExperience::tryFrom((string) $request->request->get('experience'));

        if (null === $experience) {
            return new Response('Unknown answer', Response::HTTP_BAD_REQUEST);
        }

        $user = $this->getUser();

        if ($user instanceof User) {
            $user->setChessExperience($experience);
            $this->entityManager->flush();
        }

        $response = new Response(status: Response::HTTP_NO_CONTENT);
        $response->headers->setCookie(ChessExperienceCookie::create($experience, $request->isSecure()));

        return $response;
    }
}
