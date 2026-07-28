<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Form\RegisterType;
use App\Repository\UserRepository;
use App\Service\UsernameGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Self-service email/password account creation (see App\Form\RegisterType).
 */
#[AsController]
class RegisterAction extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UsernameGenerator $usernameGenerator,
    ) {
    }

    #[Route(path: '/register', name: 'register', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): RedirectResponse|array
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('lobby');
        }

        $form = $this->createForm(RegisterType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();

            if (null !== $this->userRepository->findByEmail($email)) {
                $form->get('email')->addError(new FormError('An account with this email already exists.'));
            } else {
                $user = new User($email);
                $user->setUsername($this->usernameGenerator->generate(null, $email));
                $user->setPassword($this->passwordHasher->hashPassword($user, $form->get('password')->getData()));
                $this->entityManager->persist($user);
                $this->entityManager->flush();

                $this->addFlash('success', 'Account created — you can now log in.');

                return $this->redirectToRoute('login');
            }
        }

        return [
            'form' => $form->createView(),
        ];
    }
}
