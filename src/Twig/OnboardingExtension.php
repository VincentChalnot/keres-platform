<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Model\ChessExperience;
use App\Service\Onboarding\ChessExperienceResolver;
use App\Service\Onboarding\OnboardingCookie;
use App\Service\Onboarding\TutorialContent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `tutorial_available()` (the tutorial's files are in place) and `onboarding_pending()` (show the home page's onboarding card: the tutorial exists and this account - or, anonymous,
 * this browser - has not been through it), `chess_experience()` (the answer to "Do you play chess?" or null).
 */
final class OnboardingExtension extends AbstractExtension
{
    public function __construct(
        private readonly TutorialContent $tutorialContent,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly ChessExperienceResolver $chessExperience,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tutorial_available', $this->tutorialContent->isAvailable(...)),
            new TwigFunction('onboarding_pending', $this->isPending(...)),
            new TwigFunction('chess_experience', fn (): ?ChessExperience => $this->chessExperience->current()),
        ];
    }

    /** A signed-in user's account decides; the browser cookie only matters for an anonymous visitor (and at sign-up). */
    private function isPending(): bool
    {
        if (!$this->tutorialContent->isAvailable()) {
            return false;
        }

        $user = $this->security->getUser();

        if ($user instanceof User) {
            return null === $user->getOnboardedAt();
        }

        return '1' !== $this->requestStack->getMainRequest()?->cookies->get(OnboardingCookie::NAME);
    }
}
