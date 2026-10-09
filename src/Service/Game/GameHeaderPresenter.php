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
use App\Model\SpeedCategory;
use App\Model\TimeControl;
use App\Model\TimeControlKind;
use App\Service\BotTournament\BotAccounts;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the header of the game page: who plays White and Black, and one
 * badge per option the game was created with (rated/casual, live evaluation,
 * time control). Shared by the live and the finished view - the page cannot
 * tell them apart, and neither can the header.
 *
 * Also the identity half of every games-list card (`GameListPresenter`).
 *
 * Usernames are only shown to a signed-in viewer, like everywhere else an
 * anonymous visitor could otherwise learn who played whom (`/lobby` feed,
 * `GameListPresenter::presentPublic()`); engine and tournament-bot seats are
 * not people, so they are always named. `$subject` is the one exception: the
 * owner of the profile page the card is listed on is named in the URL already.
 *
 * Labels are rendered in the viewer's locale when the header is built (the
 * `game` translation domain), never stored: build it per request.
 */
final readonly class GameHeaderPresenter
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function present(Game $game, ?User $viewer, ?User $subject = null): GameHeader
    {
        return new GameHeader(
            white: $this->player($game, PieceColor::WHITE, $viewer, $subject),
            black: $this->player($game, PieceColor::BLACK, $viewer, $subject),
            badges: $this->badges($game),
        );
    }

    private function player(Game $game, PieceColor $color, ?User $viewer, ?User $subject): GameHeaderPlayer
    {
        $seat = $game->getPlayer($color);

        if ($seat->isEngine()) {
            return new GameHeaderPlayer($color, $this->translator->trans('header.player.ai', ['level' => $game->getAiLevel() ?? 1], 'game'), 'ai');
        }

        $user = $seat->getUser();
        \assert(null !== $user);

        if (OpponentType::HOTSEAT === $game->getOpponentType()) {
            return new GameHeaderPlayer($color, $this->translator->trans(PieceColor::WHITE === $color ? 'header.player.player_1' : 'header.player.player_2', [], 'game'), 'human');
        }

        if (null !== BotAccounts::levelOfUser($user)) {
            return new GameHeaderPlayer($color, $user->getDisplayName() ?? $user->getUsername(), 'bot');
        }

        if (null === $viewer && $user !== $subject) {
            return new GameHeaderPlayer($color, $this->translator->trans('header.player.anonymous', [], 'game'), 'anonymous');
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
            $badges[] = new GameHeaderBadge(
                $this->translator->trans('option.rated', [], 'game'),
                'is-info',
                $this->translator->trans('header.badge.rated_title', [], 'game'),
            );
        } else {
            $badges[] = new GameHeaderBadge(
                $this->translator->trans('option.casual', [], 'game'),
                'is-light',
                $this->translator->trans('header.badge.casual_title', [], 'game'),
            );
        }

        $badges[] = new GameHeaderBadge(
            $this->timeControlLabel($game->getTimeControl()),
            'is-light',
            $this->translator->trans('header.badge.time_control_title', [], 'game'),
        );

        $speed = $game->getSpeedCategory();

        if (null !== $speed) {
            $badges[] = new GameHeaderBadge(
                $this->speedLabel($speed),
                'is-light',
                $this->translator->trans('header.badge.speed_title', [], 'game'),
            );
        }

        if ($game->isLiveEvaluationEnabled()) {
            $badges[] = new GameHeaderBadge(
                $this->translator->trans('option.live_evaluation', [], 'game'),
                'is-warning is-light',
                $this->translator->trans('header.badge.live_evaluation_title', [], 'game'),
            );
        }

        return $badges;
    }

    private function timeControlLabel(TimeControl $timeControl): string
    {
        return match ($timeControl->getKind()) {
            TimeControlKind::UNLIMITED => $this->translator->trans('time_control.unlimited', [], 'game'),
            TimeControlKind::CORRESPONDENCE => $this->translator->trans('time_control.correspondence', ['hours' => (int) $timeControl->getHoursPerMove()], 'game'),
            TimeControlKind::REALTIME => $this->realtimeLabel((int) $timeControl->getInitialSeconds(), (int) $timeControl->getIncrementSeconds()),
        };
    }

    private function realtimeLabel(int $initialSeconds, int $incrementSeconds): string
    {
        $initial = 0 === $initialSeconds % 60
            ? (string) intdiv($initialSeconds, 60)
            : $this->translator->trans('time_control.seconds_short', ['seconds' => $initialSeconds], 'game');

        return $this->translator->trans('time_control.realtime', ['initial' => $initial, 'increment' => $incrementSeconds], 'game');
    }

    private function speedLabel(SpeedCategory $speed): string
    {
        return match ($speed) {
            SpeedCategory::BULLET => $this->translator->trans('speed.bullet', [], 'game'),
            SpeedCategory::BLITZ => $this->translator->trans('speed.blitz', [], 'game'),
            SpeedCategory::RAPID => $this->translator->trans('speed.rapid', [], 'game'),
            SpeedCategory::CLASSICAL => $this->translator->trans('speed.classical', [], 'game'),
            SpeedCategory::CORRESPONDENCE => $this->translator->trans('speed.correspondence', [], 'game'),
        };
    }
}
