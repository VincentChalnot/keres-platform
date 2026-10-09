<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Entity\User;
use App\Model\GameHeader;
use App\Model\GameHeaderBadge;
use App\Model\GameHeaderPlayer;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Model\TimeControl;
use App\Model\TimeControlKind;
use App\Service\BotTournament\BotAccounts;

/**
 * Builds the header of the game page: who plays White and Black, and one
 * badge per option the game was created with (rated/casual, live evaluation,
 * time control). Shared by the live and the finished view - the page cannot
 * tell them apart, and neither can the header.
 *
 * Usernames are only shown to a signed-in viewer, like everywhere else an
 * anonymous visitor could otherwise learn who played whom (`/lobby` feed,
 * `GameListPresenter::presentPublic()`); engine and tournament-bot seats are
 * not people, so they are always named.
 */
final readonly class GameHeaderPresenter
{
    public function present(Game $game, ?User $viewer): GameHeader
    {
        return new GameHeader(
            white: $this->player($game, PieceColor::WHITE, $viewer),
            black: $this->player($game, PieceColor::BLACK, $viewer),
            badges: $this->badges($game),
        );
    }

    private function player(Game $game, PieceColor $color, ?User $viewer): GameHeaderPlayer
    {
        $seat = $game->getPlayer($color);

        if ($seat->isEngine()) {
            return new GameHeaderPlayer($color, \sprintf('AI (level %d)', $game->getAiLevel() ?? 1), 'ai');
        }

        $user = $seat->getUser();
        \assert(null !== $user);

        if (OpponentType::HOTSEAT === $game->getOpponentType()) {
            return new GameHeaderPlayer($color, PieceColor::WHITE === $color ? 'Player 1' : 'Player 2', 'human');
        }

        if (null !== BotAccounts::levelOfUser($user)) {
            return new GameHeaderPlayer($color, $user->getDisplayName() ?? $user->getUsername(), 'bot');
        }

        if (null === $viewer) {
            return new GameHeaderPlayer($color, 'Player', 'anonymous');
        }

        return new GameHeaderPlayer($color, $user->getUsername(), 'human', $user->getUsername());
    }

    /**
     * @return list<GameHeaderBadge>
     */
    private function badges(Game $game): array
    {
        $badges = [];

        if ($game->isRated()) {
            $badges[] = new GameHeaderBadge('Rated', 'is-info', 'Counts towards both players\' rating');
        } else {
            $badges[] = new GameHeaderBadge('Casual', 'is-light', 'Does not affect ratings');
        }

        $badges[] = new GameHeaderBadge($this->timeControlLabel($game->getTimeControl()), 'is-light', 'Time control');

        $speed = $game->getSpeedCategory();

        if (null !== $speed) {
            $badges[] = new GameHeaderBadge(ucfirst(strtolower($speed->name)), 'is-light', 'Speed category');
        }

        if ($game->isLiveEvaluationEnabled()) {
            $badges[] = new GameHeaderBadge('Live evaluation', 'is-warning is-light', 'The engine evaluation bar is shown during play');
        }

        return $badges;
    }

    private function timeControlLabel(TimeControl $timeControl): string
    {
        return match ($timeControl->getKind()) {
            TimeControlKind::UNLIMITED => 'Unlimited',
            TimeControlKind::CORRESPONDENCE => \sprintf('%dh / move', $timeControl->getHoursPerMove()),
            TimeControlKind::REALTIME => $this->realtimeLabel((int) $timeControl->getInitialSeconds(), (int) $timeControl->getIncrementSeconds()),
        };
    }

    private function realtimeLabel(int $initialSeconds, int $incrementSeconds): string
    {
        $initial = 0 === $initialSeconds % 60 ? (string) intdiv($initialSeconds, 60) : \sprintf('%ds', $initialSeconds);

        return \sprintf('%s+%d', $initial, $incrementSeconds);
    }
}
