<?php

declare(strict_types=1);

namespace App\Action\Settings;

use App\Entity\User;
use App\Form\ProfileSettingsType;
use App\Service\UsernameGenerator;
use App\Service\UserPreferencesManager;
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
 * `GET|POST /settings/profile` - Settings -> Profile (05-social.md sec
 * 9.2). Identity (`User`: username, display name) and personal details
 * (`UserPreferences`: names, language, country) in one form. The username
 * may change once every `MultiplayerLimits::USERNAME_CHANGE_INTERVAL`
 * (sec 1.6); a pure case change is always free.
 */
#[AsController]
class SettingsProfileAction extends AbstractController
{
    public function __construct(
        private readonly UsernameGenerator $usernameGenerator,
        private readonly UserPreferencesManager $userPreferencesManager,
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
            throw $this->createAccessDeniedException('User is required to edit settings.');
        }

        $preferences = $this->userPreferencesManager->getOrCreate($user);

        $form = $this->createForm(ProfileSettingsType::class, [
            'username' => $user->getUsername(),
            'displayName' => $user->getDisplayName(),
            'firstName' => $preferences->getFirstName(),
            'lastName' => $preferences->getLastName(),
            'email' => $user->getEmail(),
            'locale' => $preferences->getLocale(),
            'country' => $preferences->getCountry(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $user->setDisplayName($data['displayName'] ?: null);
            $preferences->setFirstName($data['firstName'] ?: null);
            $preferences->setLastName($data['lastName'] ?: null);
            $preferences->setLocale($data['locale'] ?: null);
            $preferences->setCountry($data['country'] ?: null);
            $preferences->touch();
            $this->entityManager->flush();

            $newUsername = $data['username'];

            if ($user->getUsername() !== $newUsername && !$this->changeUsername($form, $user, $newUsername)) {
                return $this->renderSettings($form, $user);
            }

            $this->addFlash('success', 'Profile saved.');

            return $this->redirectToRoute('settings_profile');
        }

        return $this->renderSettings($form, $user);
    }

    /** False (with the error attached to the form) when the change was refused. */
    private function changeUsername(FormInterface $form, User $user, string $newUsername): bool
    {
        // U3 (sec 1.6): a pure case change is free and never gated by the
        // cooldown - the folded form is unchanged so no third party is affected.
        $isPureCaseChange = 0 === strcasecmp($user->getUsername(), $newUsername);
        $now = $this->clock->now();

        if (!$isPureCaseChange && !$user->canChangeUsername($now)) {
            $form->get('username')->addError(new FormError($this->cooldownMessage($user)));

            return false;
        }

        if (!$this->usernameChangeLimiter->create((string) $user->getId())->consume(1)->isAccepted()) {
            $form->addError(new FormError('Too many attempts, try again later.'));

            return false;
        }

        if (!$this->usernameGenerator->isAvailable($newUsername, $user)) {
            $message = $this->usernameGenerator->isReserved($newUsername)
                ? 'That username is reserved.'
                : 'That username is already taken.';
            $form->get('username')->addError(new FormError($message));

            return false;
        }

        try {
            $changed = $this->usernameGenerator->change($user, $newUsername, $now);
        } catch (UniqueConstraintViolationException) {
            $changed = false;
        }

        if (!$changed) {
            // Best effort: the guarded write doesn't refresh $user on
            // failure, so this mostly catches a change made by a concurrent
            // tab before this request even started.
            $message = $user->canChangeUsername($now)
                ? 'That username was just taken.'
                : $this->cooldownMessage($user);
            $form->get('username')->addError(new FormError($message));

            return false;
        }

        return true;
    }

    private function cooldownMessage(User $user): string
    {
        return \sprintf(
            'You can only change your username once every 12 months. Next change available on %s.',
            $user->getNextUsernameChangeAt()?->format('Y-m-d') ?? 'a later date',
        );
    }

    /** @return array<string, mixed> */
    private function renderSettings(FormInterface $form, User $user): array
    {
        return [
            'section' => 'profile',
            'form' => $form->createView(),
            'user' => $user,
            'canChangeUsername' => $user->canChangeUsername($this->clock->now()),
            'nextUsernameChangeAt' => $user->getNextUsernameChangeAt(),
        ];
    }
}
