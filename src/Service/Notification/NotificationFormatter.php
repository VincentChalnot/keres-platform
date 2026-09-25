<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification;
use App\Model\Notification\NotificationType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns a stored `Notification` into what the bell, the inbox page and the
 * JSON list show: one line of text plus the link it opens. Rendering lives
 * here, not in the row, so wording can change without a data migration.
 */
final readonly class NotificationFormatter
{
    private const int ENCODE_FLAGS = \JSON_THROW_ON_ERROR
        | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** @return array{uuid: string, type: string, text: string, url: string, icon: string, read: bool, createdAt: string} */
    public function format(Notification $notification): array
    {
        $payload = $notification->getPayload();
        $actor = $this->actorName($payload);
        $gameUrl = \is_string($payload['gameUuid'] ?? null)
            ? $this->urlGenerator->generate('play', ['uuid' => $payload['gameUuid']])
            : $this->urlGenerator->generate('game_list');

        [$text, $url, $icon] = match ($notification->getType()) {
            NotificationType::FRIEND_REQUEST => [
                \sprintf('%s sent you a friend request.', $actor),
                $this->urlGenerator->generate('friends'),
                'fa-user-plus',
            ],
            NotificationType::FRIEND_ACCEPTED => [
                \sprintf('%s is now your friend.', $actor),
                \is_string($payload['actor']['username'] ?? null)
                    ? $this->urlGenerator->generate('profile', ['username' => $payload['actor']['username']])
                    : $this->urlGenerator->generate('friends'),
                'fa-user-check',
            ],
            NotificationType::SEEK_MATCHED => [
                \sprintf('%s accepted your seek. Your game has started.', $actor),
                $gameUrl,
                'fa-chess-board',
            ],
            NotificationType::YOUR_TURN => [
                \sprintf('%s played a move. It\'s your turn.', $actor),
                $gameUrl,
                'fa-chess-knight',
            ],
            NotificationType::GAME_FINISHED => [
                $this->gameFinishedText($actor, $payload),
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
    public function formatAll(array $notifications): array
    {
        return array_map($this->format(...), $notifications);
    }

    public function encode(array $payload): string
    {
        return json_encode($payload, self::ENCODE_FLAGS);
    }

    /** @param array<string, mixed> $payload */
    private function actorName(array $payload): string
    {
        $actor = $payload['actor'] ?? null;

        if (!\is_array($actor)) {
            return 'Someone';
        }

        $name = $actor['displayName'] ?? null;

        if (!\is_string($name) || '' === $name) {
            $name = $actor['username'] ?? null;
        }

        return \is_string($name) && '' !== $name ? $name : 'Someone';
    }

    /** @param array<string, mixed> $payload */
    private function gameFinishedText(string $opponent, array $payload): string
    {
        $how = match ($payload['endReason'] ?? null) {
            'engine' => ' by checkmate',
            'resignation' => ' by resignation',
            'timeout' => ' on time',
            'abandonment' => ' by abandonment',
            'draw_agreed' => ' by agreement',
            default => '',
        };

        return match ($payload['outcome'] ?? null) {
            'win' => \sprintf('You won against %s%s.', $opponent, $how),
            'loss' => \sprintf('You lost against %s%s.', $opponent, $how),
            'draw' => \sprintf('Your game against %s ended in a draw%s.', $opponent, $how),
            'aborted' => \sprintf('Your game against %s was aborted.', $opponent),
            default => \sprintf('Your game against %s has ended.', $opponent),
        };
    }
}
