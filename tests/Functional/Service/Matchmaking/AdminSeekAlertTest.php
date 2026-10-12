<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service\Matchmaking;

use App\Entity\Notification;
use App\Entity\User;
use App\Model\ColorPreference;
use App\Model\Notification\NotificationType;
use App\Model\TimeControl;
use App\Service\Matchmaking\SeekCreationService;
use App\Service\Notification\NotificationFormatter;
use App\Tests\Functional\DatabaseTestCase;

/**
 * Administrators are told about every public seek that stays in the pool, except their own.
 */
final class AdminSeekAlertTest extends DatabaseTestCase
{
    public function testAdminIsNotifiedOfAPlayersSeekButThePlayerIsNot(): void
    {
        static::bootKernel();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $player = $this->createUser('player@example.com');

        $this->postCorrespondenceSeek($player);

        $rows = $this->seekPostedRows();
        self::assertCount(1, $rows);
        self::assertSame($admin->getId()->toRfc4122(), $rows[0]->getUser()->getId()->toRfc4122());

        $line = static::getContainer()->get(NotificationFormatter::class)->format($rows[0], 'en');
        self::assertStringContainsString($player->getDisplayName(), $line['text']);
        self::assertStringContainsString('24 h per move', $line['text']);
        self::assertSame('/lobby', $line['url']);
    }

    public function testAdminPostingASeekNotifiesNobodyElseWhenTheyAreTheOnlyAdmin(): void
    {
        static::bootKernel();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);

        $this->postCorrespondenceSeek($admin);

        self::assertSame([], $this->seekPostedRows());
    }

    private function postCorrespondenceSeek(User $user): void
    {
        static::getContainer()->get(SeekCreationService::class)->create(
            $user,
            TimeControl::correspondence(24),
            false,
            ColorPreference::RANDOM,
            false,
            null,
            null,
        );
    }

    /** @return list<Notification> */
    private function seekPostedRows(): array
    {
        return $this->em()->getRepository(Notification::class)->findBy(['type' => NotificationType::SEEK_POSTED]);
    }
}
