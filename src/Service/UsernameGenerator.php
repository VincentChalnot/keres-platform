<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UsernameGenerator
{
    private const int MAX_LENGTH = 32;
    private const int MIN_LENGTH = 3;
    private const string FALLBACK = 'player';

    /** 05-social.md sec 1.3, checked case-folded on both generation and manual change. */
    private const array RESERVED = [
        // Impersonation
        'admin', 'administrator', 'root', 'system', 'sysop', 'staff', 'official',
        'support', 'help', 'moderator', 'mod', 'keres', 'keresbot', 'security',
        'abuse', 'billing', 'noreply', 'postmaster', 'webmaster',
        // Engine / non-humans
        'ai', 'bot', 'engine', 'computer', 'cpu', 'anonymous', 'anon', 'guest',
        'deleted', 'unknown', 'null', 'undefined', 'none', 'nan',
        // Self-reference
        'me', 'self', 'you', 'my', 'mine',
        // Route-shaped (future-proofing only)
        'api', 'www', 'mail', 'static', 'assets', 'login', 'logout', 'register',
        'account', 'settings', 'profile', 'player', 'players', 'user', 'users',
        'game', 'games', 'play', 'lobby', 'seek', 'seeks', 'challenge',
        'challenges', 'friend', 'friends', 'notifications', 'push', 'leaderboard',
        'feedback', 'contact', 'dev',
    ];

    public function __construct(
        private UserRepository $userRepository,
        private Connection $connection,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function isReserved(string $username): bool
    {
        return \in_array(strtolower($username), self::RESERVED, true);
    }

    /** Format + reserved-word + case-folded-uniqueness, in that order (sec 1.6 U2). Does not itself write anything. */
    public function isAvailable(string $username, ?User $ignoring = null): bool
    {
        if (1 !== preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $username)) {
            return false;
        }

        if ($this->isReserved($username)) {
            return false;
        }

        if (null !== $ignoring && 0 === strcasecmp($ignoring->getUsername(), $username)) {
            return true; // U3: a pure case change is always available to its own owner
        }

        return !$this->userRepository->usernameExists($username);
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

        if (\strlen($candidate) < self::MIN_LENGTH || $this->isReserved($candidate)) {
            $candidate = self::FALLBACK;
        }

        return $this->deduplicate($candidate);
    }

    /**
     * The one-time username change (05-social.md sec 1.6). A guarded DBAL
     * statement, not an ORM flush, so a unique-constraint collision cannot
     * close the `EntityManager` mid-request. Returns false when the
     * allowance was already spent (`usernameChangedAt IS NOT NULL`,
     * possibly by a concurrent tab reading a stale in-memory entity); the
     * caller is expected to have validated availability first via
     * `isAvailable()`, but a concurrent claim of the same name is still
     * possible and surfaces as `UniqueConstraintViolationException`.
     */
    public function changeOnce(User $user, string $newUsername, \DateTimeImmutable $now): bool
    {
        // U3: a pure case change is free and does not consume the allowance.
        if (0 === strcasecmp($user->getUsername(), $newUsername)) {
            $user->setUsername($newUsername);
            $this->entityManager->flush();

            return true;
        }

        $affected = $this->connection->executeStatement(
            'UPDATE "user" SET username = :new, username_changed_at = :now WHERE id = :id AND username_changed_at IS NULL',
            ['new' => $newUsername, 'now' => $now->format('Y-m-d H:i:sP'), 'id' => $user->getId()->toRfc4122()],
        );

        if (0 === $affected) {
            return false;
        }

        $user->setUsername($newUsername);
        $user->setUsernameChangedAt($now);

        return true;
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
