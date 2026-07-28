<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\Form\RegisterType;
use App\Repository\UserRepository;
use App\Service\UserMailer;
use App\Service\UsernameGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Self-service email/password account creation (see App\Form\RegisterType).
 *
 * Never discloses whether an email is already registered
 * (05-social.md sec 2.1: "the front door" - `LostPasswordAction` already
 * keeps this discipline for password resets; this action predated that
 * discipline and is fixed here, sec 11 open question 5). Both the
 * real-creation and already-exists branches render the exact same flash
 * and redirect; the already-exists branch instead emails the existing
 * account so its owner learns about the attempt without a public oracle.
 */
#[AsController]
class RegisterAction extends AbstractController
{
    private const string GENERIC_SUCCESS_MESSAGE = 'Check your email to finish setting up your account.';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UsernameGenerator $usernameGenerator,
        private readonly UserMailer $userMailer,
        private readonly UrlGeneratorInterface $urlGenerator,
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
            $existing = $this->userRepository->findByEmail($email);

            if (null !== $existing) {
                $lostPasswordUrl = $this->urlGenerator->generate('lost_password', [], UrlGeneratorInterface::ABSOLUTE_URL);
                $this->userMailer->sendAccountAlreadyExistsMail($existing, $lostPasswordUrl);
            } else {
                $user = new User($email);
                $user->setUsername($this->usernameGenerator->generate(null, $email));
                $user->setPassword($this->passwordHasher->hashPassword($user, $form->get('password')->getData()));
                $this->entityManager->persist($user);
                $this->entityManager->flush();
            }

            // Byte-identical either way - that's the whole point (sec 2.1).
            $this->addFlash('success', self::GENERIC_SUCCESS_MESSAGE);

            return $this->redirectToRoute('login');
        }

        return [
            'form' => $form->createView(),
        ];
    }
}
