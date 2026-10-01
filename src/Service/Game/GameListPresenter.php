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
 */
final readonly class GameListPresenter
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function present(Game $game, User $subject, bool $isSelf): GameListRow
    {
        $isGameOver = $game->isGameOver();

        return new GameListRow(
            uuid: (string) $game->getUuid(),
            opponentLabel: $this->opponentLabel($game, $subject),
            turnLabel: $isGameOver ? null : $this->turnLabel($game, $subject, $isSelf),
            resultLabel: $isGameOver ? $this->resultLabel($game) : null,
            timeRemainingLabel: $isGameOver ? null : $this->remainingLabel($game),
            lastActivityAt: $game->getLastMoveAt() ?? $game->getCreatedAt(),
            isGameOver: $isGameOver,
        );
    }

    private function opponentLabel(Game $game, User $subject): string
    {
        $opponentType = $game->getOpponentType();

        if (OpponentType::HOTSEAT === $opponentType) {
            return 'Hot-seat game';
        }

        if (OpponentType::AI === $opponentType) {
            return \sprintf('AI (level %d)', $game->getAiLevel() ?? 1);
        }

        $opponent = $game->getOpponentOf($subject);

        return null !== $opponent ? ($opponent->getDisplayName() ?? $opponent->getUsername()) : 'Multiplayer';
    }

    private function turnLabel(Game $game, User $subject, bool $isSelf): string
    {
        if (OpponentType::HOTSEAT === $game->getOpponentType()) {
            return $game->isWhiteTurn() ? 'White to move' : 'Black to move';
        }

        if ($isSelf) {
            return $game->isTurnOf($subject) ? 'Your turn' : 'Their turn';
        }

        return 'Next to play: '.($game->isWhiteTurn() ? 'White' : 'Black');
    }

    private function resultLabel(Game $game): string
    {
        return match (true) {
            GameEndReason::ABORTED === $game->getEndReason() => 'Aborted',
            $game->isDraw() => 'Draw',
            $game->isWhiteWins() => 'White wins',
            default => 'Black wins',
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
            return 'Overdue';
        }

        $hours = intdiv($seconds, 3600);

        if ($hours >= 1) {
            return \sprintf('%dh left', $hours);
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes >= 1) {
            return \sprintf('%dm left', $minutes);
        }

        return \sprintf('%ds left', $seconds);
    }
}
