<?php

declare(strict_types=1);

namespace App\Service\Ladder;

use App\Entity\User;
use App\Form\LocalGameType;
use App\Service\BotTournament\BotAccounts;
use Symfony\Component\HttpFoundation\Request;

/**
 * The bot the lobby proposes next: one level above the highest the player beat (`User::$highestAiLevelBeaten`; for a
 * guest, the `keres_ai_beaten` cookie). Past level 10 the last one stays on offer; a guest who beat every level they are
 * allowed (`LocalGameType::GUEST_MAX_AI_LEVEL`) stays on the last of them and is told an account unlocks the rest.
 */
final readonly class NextBot
{
    private const int LAST_LEVEL = 10;

    public function __construct(
        private BotAccounts $botAccounts,
    ) {
    }

    /** @return array{level: int, avatarUrl: ?string, capped: bool} */
    public function forRequest(?User $user, Request $request): array
    {
        if ($user instanceof User) {
            $level = min(($user->getHighestAiLevelBeaten() ?? 0) + 1, self::LAST_LEVEL);
            $capped = false;
        } else {
            $beaten = GuestAiProgress::levelBeaten($request);
            $level = min($beaten + 1, LocalGameType::GUEST_MAX_AI_LEVEL);
            $capped = $beaten >= LocalGameType::GUEST_MAX_AI_LEVEL;
        }

        return ['level' => $level, 'avatarUrl' => $this->botAccounts->avatarUrl($level), 'capped' => $capped];
    }
}
