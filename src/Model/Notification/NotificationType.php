<?php

declare(strict_types=1);

namespace App\Model\Notification;

/**
 * Every kind of in-app notification (07-notifications.md sec 1.2). The
 * backing value is the wire/storage string (`notification.type`, the
 * `user/{uuid}` frame, the JSON list), so renaming a case's value is a
 * data migration.
 *
 * Adding a type: add a case here, its `settings_type.<value>` key in the
 * `notifications` translation domain (en + fr), then one arm in
 * `NotificationFormatter::format()` (with its own `text.*` keys) and one
 * call to `NotificationCenter::notify()` where the event happens. The
 * settings toggle (unless the type is left out of `userConfigurable()`), the inbox and the bell pick it up without further changes.
 */
enum NotificationType: string
{
    /** Translation key (domain `notifications`) of the label shown next to the toggle in Settings -> Notifications. */
    public function labelKey(): string
    {
        return 'settings_type.'.$this->value;
    }

    /** Default for a user who never touched the toggle (sec 8.2). Every in-app type is on: an inbox row costs nothing. */
    public function isEnabledByDefault(): bool
    {
        return true;
    }

    /**
     * All emails enabled by default.
     */
    public function isEmailEnabledByDefault(): bool
    {
        return true;
    }

    /**
     * The types a user can switch on and off in Settings -> Notifications.
     *
     * @return list<self>
     */
    public static function userConfigurable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type): bool => self::SEEK_POSTED !== $type));
    }

    case FRIEND_REQUEST = 'friend_request';
    case FRIEND_ACCEPTED = 'friend_accepted';
    case SEEK_MATCHED = 'seek_matched';
    case REMATCH_OFFERED = 'rematch_offered';
    case YOUR_TURN = 'your_turn';
    case GAME_FINISHED = 'game_finished';
    /** Admins only (`AdminSeekAlert`): a public seek was posted. Not a user choice, so no settings toggle. */
    case SEEK_POSTED = 'seek_posted';
}
