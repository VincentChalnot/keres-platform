<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Service\Onboarding\OnboardingCookie;
use App\Service\Onboarding\TutorialContent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `tutorial_available()` (the tutorial's files are in place) and `onboarding_pending()` (show the invitation banner:
 * the tutorial exists and neither this browser nor this account has been through it).
 */
final class OnboardingExtension extends AbstractExtension
{
    public function __construct(
        private readonly TutorialContent $tutorialContent,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tutorial_available', $this->tutorialContent->isAvailable(...)),
            new TwigFunction('onboarding_pending', $this->isPending(...)),
        ];
    }

    private function isPending(): bool
    {
        if (!$this->tutorialContent->isAvailable()) {
            return false;
        }

        if ('1' === $this->requestStack->getMainRequest()?->cookies->get(OnboardingCookie::NAME)) {
            return false;
        }

        $user = $this->security->getUser();

        return !$user instanceof User || null === $user->getOnboardedAt();
    }
}
