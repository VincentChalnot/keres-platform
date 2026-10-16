<?php

declare(strict_types=1);

namespace App\Service\Onboarding;

use App\Entity\User;
use App\Model\ChessExperience;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The visitor's answer to "Do you play chess?", or null when it was never given. A signed-in user's account wins
 * (the cookie of the browser they happen to use says nothing about them); the cookie only speaks for anonymous
 * visitors.
 */
final readonly class ChessExperienceResolver
{
    public function __construct(
        private RequestStack $requestStack,
        private Security $security,
    ) {
    }

    public function current(): ?ChessExperience
    {
        $user = $this->security->getUser();

        if ($user instanceof User) {
            return $user->getChessExperience();
        }

        $request = $this->requestStack->getMainRequest();

        return null === $request ? null : ChessExperienceCookie::read($request);
    }
}
