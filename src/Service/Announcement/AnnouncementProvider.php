<?php

declare(strict_types=1);

namespace App\Service\Announcement;

use App\Model\Announcement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The announcements shown on the player dashboard and the anonymous lobby
 * page: dated entries whose texts live in the `announcements` translation
 * domain under `items.<id>.title` / `items.<id>.body` (both locales).
 *
 * Adding an announcement = one line in {@see self::ENTRIES} + those two keys
 * in `announcements+intl-icu.en.yaml` and `.fr.yaml` (see AGENTS.md).
 */
final readonly class AnnouncementProvider
{
    public const string DOMAIN = 'announcements';

    /**
     * id => release date (Y-m-d). Any order: entries are sorted newest first,
     * ties keeping the order written here.
     */
    public const array ENTRIES = [
        'french_translation' => '2026-10-10',
        'fullscreen_layout' => '2026-10-10',
        'rotate_opponent_pieces' => '2026-10-10',
        'guest_limits' => '2026-10-10',
        'persistent_login' => '2026-10-09',
        'mobile_menu' => '2026-10-09',
        'game_over_reason' => '2026-10-09',
        'game_players_options' => '2026-10-09',
        'result_banner' => '2026-10-09',
        'stack_or_select' => '2026-10-09',
        'live_evaluation' => '2026-10-09',
        'new_look' => '2026-10-09',
        'hidden_games_feed' => '2026-10-09',
        'email_notifications' => '2026-10-02',
        'v1' => '2026-10-01',
    ];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Newest first, translated into the current locale.
     *
     * @return list<Announcement>
     */
    public function latest(?int $limit = null): array
    {
        $ids = array_keys(self::ENTRIES);
        // Stable sort (PHP 8+): equal dates keep their declared order.
        usort($ids, static fn (string $a, string $b): int => self::ENTRIES[$b] <=> self::ENTRIES[$a]);

        if (null !== $limit) {
            $ids = \array_slice($ids, 0, $limit);
        }

        return array_map(fn (string $id): Announcement => new Announcement(
            $id,
            new \DateTimeImmutable(self::ENTRIES[$id]),
            $this->translator->trans(self::titleKey($id), [], self::DOMAIN),
            $this->translator->trans(self::bodyKey($id), [], self::DOMAIN),
        ), $ids);
    }

    public static function titleKey(string $id): string
    {
        return 'items.'.$id.'.title';
    }

    public static function bodyKey(string $id): string
    {
        return 'items.'.$id.'.body';
    }
}
