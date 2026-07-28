<?php

declare(strict_types=1);

namespace App\Action;

use App\Form\LostPasswordType;
use App\Repository\UserRepository;
use App\Service\UserMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Adapted from SidusUserBundle\Action\LostPasswordAction: replaces
 * UserManagerInterface::findByUsername/requestNewPassword with a direct
 * UserRepository lookup + inline token generation, but keeps the
 * "don't disclose whether the account exists" behavior.
 */
#[AsController]
class LostPasswordAction extends AbstractController
{
    private const string RESET_TOKEN_TTL = '+1 hour';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserMailer $userMailer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route(path: '/login/lost-password', name: 'lost_password', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): RedirectResponse|array
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('lobby');
        }

        $form = $this->createForm(LostPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->userRepository->findByEmail($form->get('email')->getData());

            if (null !== $user) {
                $plainToken = bin2hex(random_bytes(32));
                $user->setResetToken(hash('sha256', $plainToken));
                $user->setResetTokenExpiresAt(new \DateTimeImmutable(self::RESET_TOKEN_TTL));
                $this->entityManager->flush();

                $resetUrl = $this->urlGenerator->generate('reset_password', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_URL);
                $this->userMailer->sendResetPasswordMail($user, $resetUrl);
            }

            // Do not disclose whether an account exists for this email:
            // always show the same generic confirmation.
            $this->addFlash('success', 'If an account exists for that email, a reset link has been sent.');

            return $this->redirectToRoute('login');
        }

        return [
            'form' => $form->createView(),
        ];
    }
}
