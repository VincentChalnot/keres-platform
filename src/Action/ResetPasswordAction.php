<?php

declare(strict_types=1);

namespace App\Action;

use App\Form\ResetPasswordType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Adapted from SidusUserBundle\Action\ResetPasswordAction: looks up the
 * user by (hashed) resetToken, checks resetTokenExpiresAt, and hashes the
 * new password via the standard symfony/password-hasher service instead of
 * the bundle's UserManagerInterface.
 */
#[AsController]
class ResetPasswordAction extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route(path: '/login/reset-password', name: 'reset_password', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): RedirectResponse|array
    {
        if ($this->getUser()) {
            $this->addFlash('error', 'You are already logged in.');

            return $this->redirectToRoute('new_game');
        }

        $token = $request->query->get('token');

        if (!$token) {
            $this->addFlash('error', 'Missing reset token.');

            return $this->redirectToRoute('login');
        }

        $user = $this->userRepository->findByValidResetTokenHash(hash('sha256', $token));

        if (null === $user) {
            $this->addFlash('error', 'This reset link is invalid or has expired.');

            return $this->redirectToRoute('lost_password');
        }

        $form = $this->createForm(ResetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $form->get('password')->getData()));
            $user->setResetToken(null);
            $user->setResetTokenExpiresAt(null);
            $this->entityManager->flush();

            $this->addFlash('success', 'Your password has been reset — you can now log in.');

            return $this->redirectToRoute('login');
        }

        return [
            'user' => $user,
            'form' => $form->createView(),
        ];
    }
}
