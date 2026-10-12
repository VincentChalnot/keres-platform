<?php

declare(strict_types=1);

namespace App\Service\BotTournament;

use App\Entity\User;
use App\Model\Notification\NotificationPreferences;
use App\Model\Notification\NotificationType;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The dedicated accounts (one per engine AI level) the bot tournament plays
 * under: `bot-level-N@playkeres.com`.
 */
class BotAccounts
{
    /** SQL `LIKE` pattern matching every bot account's e-mail (the bots are not players: leaderboards leave them out). */
    public const string EMAIL_LIKE = 'bot-level-%@playkeres.com';

    private const string EMAIL_PATTERN = 'bot-level-%d@playkeres.com';
    private const string USERNAME_PATTERN = 'keres-bot-%d';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** Where the portrait of a bot level is served from (`public/images/bots/level-N.webp`, square, supplied by the operator). */
    public static function avatarPath(int $level): string
    {
        return \sprintf('/images/bots/level-%d.webp', $level);
    }

    /** The portrait of a bot level, null while its file has not been dropped in `public/`. */
    public function avatarUrl(int $level): ?string
    {
        return is_file($this->projectDir.'/public'.self::avatarPath($level)) ? self::avatarPath($level) : null;
    }

    /** The AI level a bot account (`bot-level-N@playkeres.com`) plays at; null for any other user. */
    public static function levelOfUser(User $user): ?int
    {
        $prefix = 'bot-level-';
        $suffix = '@playkeres.com';
        $email = $user->getEmail();

        if (!str_starts_with($email, $prefix) || !str_ends_with($email, $suffix)) {
            return null;
        }

        $level = substr($email, \strlen($prefix), -\strlen($suffix));

        return ctype_digit($level) ? (int) $level : null;
    }

    /**
     * Creates missing accounts (and silences their notifications: fake
     * mailbox, empty bell), and points every requested account at its portrait.
     *
     * @param list<int> $levels
     *
     * @return array<int, string> level => user uuid
     */
    public function ensure(array $levels): array
    {
        $ids = [];

        foreach ($levels as $level) {
            $user = $this->userRepository->findByEmail(\sprintf(self::EMAIL_PATTERN, $level));

            if (null === $user) {
                $user = new User(\sprintf(self::EMAIL_PATTERN, $level));
                $user->setUsername(\sprintf(self::USERNAME_PATTERN, $level));
                $user->setDisplayName(\sprintf('Keres Bot (level %d)', $level));
                $this->entityManager->persist($user);
            }

            $user->setAvatarUrl($this->avatarUrl($level));

            $enabled = array_fill_keys(array_map(static fn (NotificationType $t): string => $t->value, NotificationType::cases()), false);
            NotificationPreferences::fromUser($user)->withEmail($enabled)->withInApp($enabled)->applyTo($user);

            $this->entityManager->flush();
            $ids[$level] = $user->getId()->toRfc4122();
        }

        $this->entityManager->clear();

        return $ids;
    }

    /**
     * Accounts that already exist, level => user uuid. Levels without an
     * account get a placeholder uuid so callers can still plan with them
     * (dry runs).
     *
     * @param list<int> $levels
     *
     * @return array<int, string>
     */
    public function existingIds(array $levels): array
    {
        $existing = $this->allIds();
        $ids = [];

        foreach ($levels as $level) {
            $ids[$level] = $existing[$level] ?? '00000000-0000-0000-0000-'.str_pad((string) $level, 12, '0', \STR_PAD_LEFT);
        }

        return $ids;
    }

    /**
     * Every bot account in the database, level => user uuid (ascending level).
     *
     * @return array<int, string>
     */
    public function allIds(): array
    {
        $prefix = 'bot-level-';
        $suffix = '@playkeres.com';
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, email FROM "user" WHERE email LIKE :pattern',
            ['pattern' => self::EMAIL_LIKE],
        );

        $ids = [];

        foreach ($rows as $row) {
            $level = substr((string) $row['email'], \strlen($prefix), -\strlen($suffix));

            if (ctype_digit($level)) {
                $ids[(int) $level] = (string) $row['id'];
            }
        }

        ksort($ids);

        return $ids;
    }
}
