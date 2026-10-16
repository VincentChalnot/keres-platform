<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\Ladder\GuestAiProgress;
use App\Service\Onboarding\ChessExperienceCookie;
use App\Service\Onboarding\OnboardingCookie;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What an anonymous visitor did in this browser follows them into the account they create, whichever sign-up path
 * (e-mail form, OIDC callback, dev login) persists it: the tutorial flag, the answer to "Do you play chess?" and the AI levels beaten as a guest. Outside a request (console, bots)
 * there is nothing to carry over.
 */
#[AsEntityListener(event: Events::prePersist, entity: User::class)]
final readonly class NewUserBrowserProgressListener
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function __invoke(User $user): void
    {
        $request = $this->requestStack->getMainRequest();

        if (null === $request) {
            return;
        }

        if ('1' === $request->cookies->get(OnboardingCookie::NAME)) {
            $user->markOnboarded(new \DateTimeImmutable());
        }

        $user->setChessExperience(ChessExperienceCookie::read($request));

        $beaten = GuestAiProgress::levelBeaten($request);

        if ($beaten > 0) {
            $user->recordAiLevelBeaten($beaten);
        }
    }
}
