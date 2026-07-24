<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\UserPreferences;
use App\Repository\UserPreferencesRepository;
use Doctrine\ORM\EntityManagerInterface;

class UserPreferencesManager
{
    public function __construct(
        private readonly UserPreferencesRepository $userPreferencesRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getOrCreate(User $user): UserPreferences
    {
        $preferences = $this->userPreferencesRepository->findByUser($user);

        if (null !== $preferences) {
            return $preferences;
        }

        $preferences = new UserPreferences($user);
        $this->entityManager->persist($preferences);
        $this->entityManager->flush();

        return $preferences;
    }
}
