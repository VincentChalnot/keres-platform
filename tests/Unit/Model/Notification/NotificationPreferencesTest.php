<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model\Notification;

use App\Entity\User;
use App\Model\Notification\NotificationPreferences;
use App\Model\Notification\NotificationType;
use PHPUnit\Framework\TestCase;

/** 07-notifications.md sec 0.3 / 8.3: defaults merged in, only non-defaults stored. */
final class NotificationPreferencesTest extends TestCase
{
    public function testEmptyBlobResolvesToDefaults(): void
    {
        $preferences = NotificationPreferences::fromUser(new User('a@example.com'));

        foreach (NotificationType::cases() as $type) {
            self::assertSame($type->isEnabledByDefault(), $preferences->isInAppEnabled($type));
        }
    }

    public function testOnlyNonDefaultValuesArePersisted(): void
    {
        $user = new User('a@example.com');

        NotificationPreferences::fromUser($user)
            ->withInApp(['friend_request' => false, 'game_finished' => true, 'not_a_type' => false])
            ->applyTo($user);

        self::assertSame(['version' => 1, 'inApp' => ['friend_request' => false]], $user->getNotificationPreferences());
        self::assertFalse(NotificationPreferences::fromUser($user)->isInAppEnabled(NotificationType::FRIEND_REQUEST));
        self::assertTrue(NotificationPreferences::fromUser($user)->isInAppEnabled(NotificationType::GAME_FINISHED));
    }

    public function testUnrelatedKeysArePreserved(): void
    {
        $user = new User('a@example.com');
        $user->setNotificationPreferences(['push' => ['yourTurnCorrespondence' => true], 'inApp' => ['your_turn' => 'garbage']]);

        $preferences = NotificationPreferences::fromUser($user);
        self::assertTrue($preferences->isInAppEnabled(NotificationType::YOUR_TURN), 'a non-bool value falls back to the default');

        $preferences->withInApp(['your_turn' => false])->applyTo($user);

        self::assertSame(['yourTurnCorrespondence' => true], $user->getNotificationPreferences()['push']);
        self::assertSame(['your_turn' => false], $user->getNotificationPreferences()['inApp']);
    }
}
