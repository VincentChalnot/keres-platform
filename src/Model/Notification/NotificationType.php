<?php

declare(strict_types=1);

namespace App\Model\Notification;

/**
 * Every kind of in-app notification (07-notifications.md sec 1.2). The
 * backing value is the wire/storage string (`notification.type`, the
 * `user/{uuid}` frame, the JSON list), so renaming a case's value is a
 * data migration.
 *
 * Adding a type: add a case here with its `label()`, then one arm in
 * `NotificationFormatter::format()` and one call to
 * `NotificationCenter::notify()` where the event happens. The settings
 * toggle, the inbox and the bell pick it up without further changes.
 */
enum NotificationType: string
{
    /** Shown next to the toggle in Settings -> Notifications. */
    public function label(): string
    {
        return match ($this) {
            self::FRIEND_REQUEST => 'Someone sends me a friend request',
            self::FRIEND_ACCEPTED => 'Someone accepts my friend request',
            self::SEEK_MATCHED => 'Someone accepts my seek and a game starts',
            self::YOUR_TURN => 'My opponent plays a move in a correspondence or unlimited game',
            self::GAME_FINISHED => 'One of my games ends',
        };
    }

    /** Default for a user who never touched the toggle (sec 8.2). Every in-app type is on: an inbox row costs nothing. */
    public function isEnabledByDefault(): bool
    {
        return true;
    }
    case FRIEND_REQUEST = 'friend_request';
    case FRIEND_ACCEPTED = 'friend_accepted';
    case SEEK_MATCHED = 'seek_matched';
    case YOUR_TURN = 'your_turn';
    case GAME_FINISHED = 'game_finished';
}
