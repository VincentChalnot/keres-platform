<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Form\AcceptTermsType;
use App\Model\TermsOfUse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where `TermsAcceptanceListener` sends a signed-in account that has not accepted the current terms of use
 * (OIDC sign-ups, accounts that predate the terms, a new version). Accepting stores the version and the date.
 */
#[AsController]
class AcceptTermsAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/terms/accept', name: 'terms_accept', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): RedirectResponse|array
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->redirectToRoute('login');
        }

        $redirect = $this->safeRedirectTarget($request);
        $form = $this->createForm(AcceptTermsType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->acceptTerms(TermsOfUse::CURRENT_VERSION, new \DateTimeImmutable());
            $this->entityManager->flush();

            return null !== $redirect ? new RedirectResponse($redirect) : $this->redirectToRoute('index');
        }

        return [
            'form' => $form->createView(),
            'redirect' => $redirect,
        ];
    }

    /** Only a local path: `/x` yes, `//host` and `https://host` no. */
    private function safeRedirectTarget(Request $request): ?string
    {
        $target = $request->query->get('redirect');

        if (\is_string($target) && str_starts_with($target, '/') && !str_starts_with($target, '//') && !str_contains($target, '\\')) {
            return $target;
        }

        return null;
    }
}
