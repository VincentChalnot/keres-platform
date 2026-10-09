<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification;
use App\Model\Notification\NotificationType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns a stored `Notification` into what the bell, the inbox page and the
 * JSON list show: one line of text plus the link it opens. Rendering lives
 * here, not in the row, so wording can change without a data migration. The
 * text is translated (`notifications` domain) at display time: in the
 * current request's locale by default, or in an explicit `$locale`.
 */
final readonly class NotificationFormatter
{
    private const int ENCODE_FLAGS = \JSON_THROW_ON_ERROR
        | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param string|null $locale `null` = the current request's locale
     *
     * @return array{uuid: string, type: string, text: string, url: string, icon: string, read: bool, createdAt: string}
     */
    public function format(Notification $notification, ?string $locale = null): array
    {
        $payload = $notification->getPayload();
        $actor = $this->actorName($payload, $locale);
        $gameUrl = \is_string($payload['gameUuid'] ?? null)
            ? $this->urlGenerator->generate('play', ['uuid' => $payload['gameUuid']])
            : $this->urlGenerator->generate('game_list');

        [$text, $url, $icon] = match ($notification->getType()) {
            NotificationType::FRIEND_REQUEST => [
                $this->translator->trans('text.friend_request', ['actor' => $actor], 'notifications', $locale),
                $this->urlGenerator->generate('friends'),
                'fa-user-plus',
            ],
            NotificationType::FRIEND_ACCEPTED => [
                $this->translator->trans('text.friend_accepted', ['actor' => $actor], 'notifications', $locale),
                \is_string($payload['actor']['username'] ?? null)
                    ? $this->urlGenerator->generate('profile', ['username' => $payload['actor']['username']])
                    : $this->urlGenerator->generate('friends'),
                'fa-user-check',
            ],
            NotificationType::SEEK_MATCHED => [
                $this->translator->trans('text.seek_matched', ['actor' => $actor], 'notifications', $locale),
                $gameUrl,
                'fa-chess-board',
            ],
            NotificationType::YOUR_TURN => [
                $this->translator->trans('text.your_turn', ['actor' => $actor], 'notifications', $locale),
                $gameUrl,
                'fa-chess-knight',
            ],
            NotificationType::GAME_FINISHED => [
                $this->gameFinishedText($actor, $payload, $locale),
                $gameUrl,
                'fa-flag-checkered',
            ],
        };

        return [
            'uuid' => $notification->getUuid()->toRfc4122(),
            'type' => $notification->getType()->value,
            'text' => $text,
            'url' => $url,
            'icon' => $icon,
            'read' => $notification->isRead(),
            'createdAt' => $notification->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param list<Notification> $notifications
     *
     * @return list<array<string, mixed>>
     */
    public function formatAll(array $notifications, ?string $locale = null): array
    {
        return array_map(fn (Notification $notification): array => $this->format($notification, $locale), $notifications);
    }

    public function encode(array $payload): string
    {
        return json_encode($payload, self::ENCODE_FLAGS);
    }

    /** @param array<string, mixed> $payload */
    private function actorName(array $payload, ?string $locale): string
    {
        $actor = $payload['actor'] ?? null;

        if (\is_array($actor)) {
            foreach (['displayName', 'username'] as $key) {
                $name = $actor[$key] ?? null;

                if (\is_string($name) && '' !== $name) {
                    return $name;
                }
            }
        }

        return $this->translator->trans('text.someone', [], 'notifications', $locale);
    }

    /** @param array<string, mixed> $payload */
    private function gameFinishedText(string $opponent, array $payload, ?string $locale): string
    {
        $key = match ($payload['outcome'] ?? null) {
            'win' => 'text.game_finished.win',
            'loss' => 'text.game_finished.loss',
            'draw' => 'text.game_finished.draw',
            'aborted' => 'text.game_finished.aborted',
            default => 'text.game_finished.ended',
        };

        // Unknown or missing end reasons fall into the select's `other` branch (no suffix).
        $reason = \is_string($payload['endReason'] ?? null) ? $payload['endReason'] : 'none';

        return $this->translator->trans($key, ['opponent' => $opponent, 'reason' => $reason], 'notifications', $locale);
    }
}
