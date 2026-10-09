<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Entity\User;
use App\Model\GameEndReason;
use App\Model\GameListRow;
use App\Model\OpponentType;
use App\Model\PieceColor;
use App\Model\TimeControlKind;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * T13: builds the one `GameListRow` shape every games listing renders,
 * replacing the three divergent per-template implementations that used to
 * exist (the dashboard widget, `/games`, and a profile's game history).
 *
 * `$subject` is whose perspective the row is told from (opponent naming,
 * "Your turn" vs "Their turn") - the dashboard and `/games` always pass the
 * logged-in viewer as `$subject` with `$isSelf = true`; a profile page
 * passes the profile owner, with `$isSelf` reflecting whether the viewer
 * *is* that owner. A spectator (`$isSelf = false`) never sees "Your turn" -
 * that phrasing is only meaningful from the subject's own point of view -
 * and gets "Next to play: White/Black" instead, same as the pre-T13
 * profile page already did.
 *
 * Labels are translated into the viewer's locale when the row is built
 * (domain `game`); never cache or store a built row.
 */
final readonly class GameListPresenter
{
    public function __construct(
        private ClockInterface $clock,
        private GameHeaderPresenter $gameHeaderPresenter,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * `$viewer` is who is looking at the list: it decides whether usernames
     * are shown (see `GameHeaderPresenter`). It is the subject on a self
     * list; only a profile page, which anyone may open, passes someone else
     * - or null for an anonymous visitor.
     */
    public function present(Game $game, User $subject, bool $isSelf, ?User $viewer = null): GameListRow
    {
        $isGameOver = $game->isGameOver();

        return new GameListRow(
            uuid: (string) $game->getUuid(),
            header: $this->gameHeaderPresenter->present($game, $isSelf ? $subject : $viewer, $subject),
            turnLabel: $isGameOver ? null : $this->turnLabel($game, $subject, $isSelf),
            resultLabel: $isGameOver ? $this->resultLabel($game) : null,
            timeRemainingLabel: $isGameOver ? null : $this->remainingLabel($game),
            lastActivityAt: $game->getLastMoveAt() ?? $game->getCreatedAt(),
            isGameOver: $isGameOver,
            isSubjectTurn: !$isGameOver && $isSelf && OpponentType::HOTSEAT !== $game->getOpponentType() && $game->isTurnOf($subject),
        );
    }

    /**
     * The row for the anonymous `/lobby` feed: told from no one's
     * perspective and naming no participant (an anonymous visitor has no
     * business learning who played whom) - engine and bot seats excepted.
     */
    public function presentPublic(Game $game): GameListRow
    {
        return new GameListRow(
            uuid: (string) $game->getUuid(),
            header: $this->gameHeaderPresenter->present($game, null),
            turnLabel: null,
            resultLabel: $this->resultLabel($game),
            timeRemainingLabel: null,
            lastActivityAt: $game->getLastMoveAt() ?? $game->getCreatedAt(),
            isGameOver: true,
        );
    }

    private function turnLabel(Game $game, User $subject, bool $isSelf): string
    {
        if (OpponentType::HOTSEAT === $game->getOpponentType()) {
            return $this->translator->trans('game_row.turn.hotseat', ['side' => $game->isWhiteTurn() ? 'white' : 'black'], 'game');
        }

        if ($isSelf) {
            return $this->translator->trans($game->isTurnOf($subject) ? 'game_row.turn.yours' : 'game_row.turn.theirs', [], 'game');
        }

        return $this->translator->trans('game_row.turn.next_to_play', ['side' => $game->isWhiteTurn() ? 'white' : 'black'], 'game');
    }

    private function resultLabel(Game $game): string
    {
        return match (true) {
            GameEndReason::ABORTED === $game->getEndReason() => $this->translator->trans('game_row.result.aborted', [], 'game'),
            $game->isDraw() => $this->translator->trans('game_row.result.draw', [], 'game'),
            $game->isWhiteWins() => $this->translator->trans('game_row.result.white_wins', [], 'game'),
            default => $this->translator->trans('game_row.result.black_wins', [], 'game'),
        };
    }

    private function remainingLabel(Game $game): ?string
    {
        return match ($game->getTimeControl()->getKind()) {
            TimeControlKind::CORRESPONDENCE => $this->correspondenceRemaining($game),
            TimeControlKind::REALTIME => $this->realtimeRemaining($game),
            TimeControlKind::UNLIMITED => null,
        };
    }

    private function correspondenceRemaining(Game $game): ?string
    {
        $deadline = $game->getMoveDeadlineAt();

        if (null === $deadline) {
            return null;
        }

        return $this->formatDuration($deadline->getTimestamp() - $this->clock->now()->getTimestamp());
    }

    /**
     * Mirrors `ClockManager::chargeAndSwap()`'s own live-remaining read
     * (same `format('Uu')` microsecond-timestamp trick), kept read-only
     * here - nothing in this class ever writes a clock column
     * (03-time-control.md sec 2.4).
     */
    private function realtimeRemaining(Game $game): ?string
    {
        $sideToMove = $game->isWhiteTurn() ? PieceColor::WHITE : PieceColor::BLACK;
        $remainingMs = $game->getPlayer($sideToMove)->getClockMsRemaining();

        if (null === $remainingMs) {
            return null;
        }

        $anchor = $game->getClockTurnStartedAt();

        if (null !== $anchor) {
            $elapsedMicros = max(0, (int) $this->clock->now()->format('Uu') - (int) $anchor->format('Uu'));
            $remainingMs = max(0, $remainingMs - intdiv($elapsedMicros, 1000));
        }

        return $this->formatDuration(intdiv($remainingMs, 1000));
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return $this->translator->trans('game_row.remaining.overdue', [], 'game');
        }

        $hours = intdiv($seconds, 3600);

        if ($hours >= 1) {
            return $this->translator->trans('game_row.remaining.hours', ['hours' => $hours], 'game');
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes >= 1) {
            return $this->translator->trans('game_row.remaining.minutes', ['minutes' => $minutes], 'game');
        }

        return $this->translator->trans('game_row.remaining.seconds', ['seconds' => $seconds], 'game');
    }
}
