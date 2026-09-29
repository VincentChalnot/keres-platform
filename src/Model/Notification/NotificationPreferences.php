<?php

declare(strict_types=1);

namespace App\Model\Notification;

use App\Entity\User;

/**
 * Typed view over `User.notificationPreferences` (07-notifications.md sec
 * 8.1/8.3). A stored blob may predate any type, so the JSON is never read
 * directly: every lookup falls back to `NotificationType::isEnabledByDefault()`,
 * and only values that differ from the default are persisted - changing a
 * default later moves every user who never touched that toggle.
 *
 * Shape: `{"version": 1, "inApp": {"<type>": bool, ...}, "email": {"<type>":
 * bool, ...}}`. Keys outside `inApp`/`email` (reserved for the future push
 * channel) are preserved as-is.
 */
final readonly class NotificationPreferences
{
    private const string IN_APP = 'inApp';
    private const string EMAIL = 'email';

    /** @param array<string, mixed> $raw */
    private function __construct(private array $raw)
    {
    }

    public static function fromUser(User $user): self
    {
        return new self($user->getNotificationPreferences());
    }

    public function isInAppEnabled(NotificationType $type): bool
    {
        $inApp = $this->raw[self::IN_APP] ?? [];
        $value = \is_array($inApp) ? ($inApp[$type->value] ?? null) : null;

        return \is_bool($value) ? $value : $type->isEnabledByDefault();
    }

    /** @return array<string, bool> every known type, resolved against defaults */
    public function inAppMap(): array
    {
        $map = [];

        foreach (NotificationType::cases() as $type) {
            $map[$type->value] = $this->isInAppEnabled($type);
        }

        return $map;
    }

    /** @param array<string, bool> $inApp keyed by `NotificationType` value; unknown keys are ignored */
    public function withInApp(array $inApp): self
    {
        $stored = [];

        foreach (NotificationType::cases() as $type) {
            $enabled = \array_key_exists($type->value, $inApp) ? (bool) $inApp[$type->value] : $this->isInAppEnabled($type);

            if ($enabled !== $type->isEnabledByDefault()) {
                $stored[$type->value] = $enabled;
            }
        }

        $raw = $this->raw;
        $raw['version'] = 1;
        $raw[self::IN_APP] = $stored;

        return new self($raw);
    }

    public function isEmailEnabled(NotificationType $type): bool
    {
        $email = $this->raw[self::EMAIL] ?? [];
        $value = \is_array($email) ? ($email[$type->value] ?? null) : null;

        return \is_bool($value) ? $value : $type->isEmailEnabledByDefault();
    }

    /** @return array<string, bool> every known type, resolved against defaults */
    public function emailMap(): array
    {
        $map = [];

        foreach (NotificationType::cases() as $type) {
            $map[$type->value] = $this->isEmailEnabled($type);
        }

        return $map;
    }

    /** @param array<string, bool> $email keyed by `NotificationType` value; unknown keys are ignored */
    public function withEmail(array $email): self
    {
        $stored = [];

        foreach (NotificationType::cases() as $type) {
            $enabled = \array_key_exists($type->value, $email) ? (bool) $email[$type->value] : $this->isEmailEnabled($type);

            if ($enabled !== $type->isEmailEnabledByDefault()) {
                $stored[$type->value] = $enabled;
            }
        }

        $raw = $this->raw;
        $raw['version'] = 1;
        $raw[self::EMAIL] = $stored;

        return new self($raw);
    }

    public function applyTo(User $user): void
    {
        $user->setNotificationPreferences($this->raw);
    }
}
