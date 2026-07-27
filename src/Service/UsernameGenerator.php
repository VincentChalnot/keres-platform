<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\UserRepository;

final readonly class UsernameGenerator
{
    private const int MAX_LENGTH = 32;
    private const int MIN_LENGTH = 3;
    private const string FALLBACK = 'player';

    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    /**
     * Generates a unique, case-insensitively distinct username derived from
     * the display name or email local-part. Matches the backfill algorithm
     * in Version20260728090000.
     */
    public function generate(?string $displayName, string $email): string
    {
        $source = $displayName ?: '';

        if ('' === $source) {
            $source = str_contains($email, '@') ? explode('@', $email, 2)[0] : $email;
        }

        $stripped = preg_replace('/[^a-zA-Z0-9_-]/', '', $source) ?? '';
        $candidate = substr($stripped, 0, self::MAX_LENGTH);

        if (\strlen($candidate) < self::MIN_LENGTH) {
            $candidate = self::FALLBACK;
        }

        return $this->deduplicate($candidate);
    }

    private function deduplicate(string $candidate): string
    {
        if (!$this->userRepository->usernameExists($candidate)) {
            return $candidate;
        }

        $n = 2;

        while (true) {
            $suffix = (string) $n;
            $deduped = substr($candidate, 0, self::MAX_LENGTH - \strlen($suffix)).$suffix;

            if (!$this->userRepository->usernameExists($deduped)) {
                return $deduped;
            }

            ++$n;
        }
    }
}
