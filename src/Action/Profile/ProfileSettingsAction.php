<?php

declare(strict_types=1);

namespace App\Action\Profile;

use App\Entity\User;
use App\Form\AccountSettingsType;
use App\Repository\FriendshipRepository;
use App\Service\UsernameGenerator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `GET|POST /settings/profile` (05-social.md sec 9.2, 09-api-reference.md
 * sec 3.3). The one-time username change (sec 1.6) plus display name,
 * rendered alongside read-only views of linked sign-in providers, blocked
 * users and pending friend requests - none of which are part of the form
 * (sec 9.2's "rendered outside the form" table).
 */
#[AsController]
class ProfileSettingsAction extends AbstractController
{
    public function __construct(
        private readonly UsernameGenerator $usernameGenerator,
        private readonly FriendshipRepository $friendshipRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly RateLimiterFactory $usernameChangeLimiter,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/settings/profile', name: 'settings_profile', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): RedirectResponse|array
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is required to edit account settings.');
        }

        $form = $this->createForm(AccountSettingsType::class, [
            'username' => $user->getUsername(),
            'displayName' => $user->getDisplayName(),
        ], [
            'canChange' => $user->canChangeUsername(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $user->setDisplayName($data['displayName'] ?: null);
            $this->entityManager->flush();

            $newUsername = $data['username'];
            // U3 (sec 1.6): a pure case change is free and never gated by
            // canChangeUsername() - the folded form is unchanged so no
            // third party is affected. Any other change still needs the
            // one-time allowance.
            $isPureCaseChange = 0 === strcasecmp($user->getUsername(), $newUsername) && $user->getUsername() !== $newUsername;

            if ($user->getUsername() !== $newUsername && ($isPureCaseChange || $user->canChangeUsername())) {
                if (!$this->usernameChangeLimiter->create((string) $user->getId())->consume(1)->isAccepted()) {
                    $form->addError(new FormError('Too many attempts, try again later.'));

                    return $this->renderSettings($form, $user);
                }

                if (!$this->usernameGenerator->isAvailable($newUsername, $user)) {
                    $message = $this->usernameGenerator->isReserved($newUsername)
                        ? 'That username is reserved.'
                        : 'That username is already taken.';
                    $form->get('username')->addError(new FormError($message));

                    return $this->renderSettings($form, $user);
                }

                try {
                    $changed = $this->usernameGenerator->changeOnce($user, $newUsername, $this->clock->now());
                } catch (UniqueConstraintViolationException) {
                    $changed = false;
                }

                if (!$changed) {
                    // Best effort: the guarded write doesn't refresh $user on
                    // failure, so this mostly catches the "already spent by a
                    // concurrent tab" case when it happened before this
                    // request even started.
                    $message = $user->canChangeUsername()
                        ? 'That username was just taken.'
                        : 'You have already used your one-time username change.';
                    $form->get('username')->addError(new FormError($message));

                    return $this->renderSettings($form, $user);
                }
            }

            return $this->redirectToRoute('settings_profile', ['saved' => 1]);
        }

        return $this->renderSettings($form, $user, $request->query->getBoolean('saved'));
    }

    /** @return array<string, mixed> */
    private function renderSettings(FormInterface $form, User $user, bool $saved = false): array
    {
        return [
            'form' => $form->createView(),
            'saved' => $saved,
            'user' => $user,
            'blockedUsers' => $this->friendshipRepository->findBlockedByUser($user),
            'outgoingRequests' => $this->friendshipRepository->findOutgoingPending($user),
            'incomingRequests' => $this->friendshipRepository->findIncomingPending($user),
        ];
    }
}
