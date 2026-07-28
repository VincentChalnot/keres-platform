<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Entity\GamePlayer;
use App\Model\MultiplayerLimits;
use App\Model\PieceColor;
use App\Model\TimeControlKind;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Nothing outside this class may write a clock column
 * (03-time-control.md sec 2.4). `readonly` with no mutable state, so worker
 * mode needs no kernel.reset (00-overview.md sec 6).
 */
final readonly class ClockManager
{
    public function __construct(
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    public function nowMicros(): int
    {
        return (int) $this->now()->format('Uu');
    }

    /** Seam for making lag compensation adaptive (03-time-control.md sec 3.3, open question 4). Constant today. */
    public function compensationMsFor(GamePlayer $player): int
    {
        return MultiplayerLimits::CLOCK_LAG_COMPENSATION_MS;
    }

    /**
     * `GameFactory` only. Sets `startedAt`, `clockTurnStartedAt`, both
     * `clockMsRemaining`, `moveDeadlineAt`. Even an UNLIMITED game gets a
     * `moveDeadlineAt` for its first two plies - the abort clamp
     * (03-time-control.md sec 7.1).
     */
    public function arm(Game $game): void
    {
        $now = $this->now();
        $game->setStartedAt($now);
        $game->setClockTurnStartedAt($now);

        $initialMs = $this->initialMsFor($game);
        $game->getPlayer(PieceColor::WHITE)->setClockMsRemaining($initialMs);
        $game->getPlayer(PieceColor::BLACK)->setClockMsRemaining($initialMs);

        $game->setMoveDeadlineAt($this->computeDeadlineAt($game, PieceColor::WHITE, $now, 0));
    }

    /**
     * `GameEngine::applyMove()` only. Charges `$mover` for the interval since
     * the anchor, credits the increment, moves the anchor to `$anchorAt`, and
     * recomputes `moveDeadlineAt` for the side to move next. If the charge
     * exceeds the mover's remaining time, returns `flagged: true` and leaves
     * every clock column untouched - the caller must reject the move and call
     * `stop()` + `GameLifecycleManager::finaliseTimeout()` instead.
     */
    public function chargeAndSwap(Game $game, PieceColor $mover, int $receivedAtMicros, int $anchorAtMicros): ClockOutcome
    {
        if ($anchorAtMicros - $receivedAtMicros > 1_000_000) {
            $this->logger->warning('Clock anchor lagged receivedAt by more than 1s - engine or platform latency degradation.', [
                'game' => $game->getUuid()->toRfc4122(),
                'receivedAtMicros' => $receivedAtMicros,
                'anchorAtMicros' => $anchorAtMicros,
            ]);
        }

        $moverPlayer = $game->getPlayer($mover);
        $remainingBefore = $moverPlayer->getClockMsRemaining();
        $chargedMs = 0;
        $flagged = false;

        if (null !== $remainingBefore) {
            $anchorMicros = (int) $game->getClockTurnStartedAt()?->format('Uu');
            $elapsedMs = intdiv(max(0, $receivedAtMicros - $anchorMicros), 1000);
            $chargedMs = max(0, $elapsedMs - $this->compensationMsFor($moverPlayer));
            $flagged = $chargedMs > $remainingBefore;
        }

        if ($flagged) {
            return new ClockOutcome(true, $chargedMs, 0, null);
        }

        $remainingAfter = $this->nextRemainingMs($game, $remainingBefore, $chargedMs);
        $moverPlayer->setClockMsRemaining($remainingAfter);

        $newAnchor = $this->microsToDateTime($anchorAtMicros);
        $game->setClockTurnStartedAt($newAnchor);

        $plies = $game->getGameMoves()->count() + 1;
        $deadline = $this->computeDeadlineAt($game, $mover->opposite(), $newAnchor, $plies);
        $game->setMoveDeadlineAt($deadline);

        return new ClockOutcome(false, $chargedMs, $remainingAfter, $deadline?->format('Uu') ? (int) $deadline->format('Uu') : null);
    }

    /**
     * `GameLifecycleManager`/`ClockAdjudicator` only. Charges the side to
     * move up to `$atMicros`, floors at 0, nulls `clockTurnStartedAt` and
     * `moveDeadlineAt`. Called with `$atMicros = moveDeadlineAt` from the
     * adjudicator so the recorded outcome is independent of discovery time
     * (03-time-control.md sec 5.1).
     */
    public function stop(Game $game, int $atMicros): void
    {
        $sideToMove = $game->isWhiteTurn() ? PieceColor::WHITE : PieceColor::BLACK;
        $player = $game->getPlayer($sideToMove);
        $remaining = $player->getClockMsRemaining();
        $anchor = $game->getClockTurnStartedAt();

        if (null !== $remaining && null !== $anchor) {
            $elapsedMs = intdiv(max(0, $atMicros - (int) $anchor->format('Uu')), 1000);
            $player->setClockMsRemaining(max(0, $remaining - $elapsedMs));
        }

        $game->setClockTurnStartedAt(null);
        $game->setMoveDeadlineAt(null);
    }

    private function initialMsFor(Game $game): ?int
    {
        $timeControl = $game->getTimeControl();

        return match ($timeControl->getKind()) {
            TimeControlKind::UNLIMITED => null,
            TimeControlKind::REALTIME => $timeControl->getInitialSeconds() * 1000,
            TimeControlKind::CORRESPONDENCE => $timeControl->getDaysPerMove() * 86_400_000,
        };
    }

    private function nextRemainingMs(Game $game, ?int $remainingBefore, int $chargedMs): ?int
    {
        $timeControl = $game->getTimeControl();

        return match ($timeControl->getKind()) {
            TimeControlKind::UNLIMITED => null,
            TimeControlKind::REALTIME => max(0, $remainingBefore - $chargedMs) + $timeControl->getIncrementSeconds() * 1000,
            TimeControlKind::CORRESPONDENCE => $timeControl->getDaysPerMove() * 86_400_000,
        };
    }

    /**
     * `plies < 2 ? min(rawDeadline ?? +inf, firstMoveCap) : rawDeadline`
     * (03-time-control.md sec 1.3). `$plies` is the ply index the upcoming
     * move will occupy - 0 and 1 are the first move of each side.
     */
    private function computeDeadlineAt(Game $game, PieceColor $sideToMove, \DateTimeImmutable $anchor, int $plies): ?\DateTimeImmutable
    {
        $clockMs = $game->getPlayer($sideToMove)->getClockMsRemaining();
        $rawDeadline = null === $clockMs ? null : $anchor->modify(\sprintf('+%d microseconds', $clockMs * 1000));

        if ($plies >= 2) {
            return $rawDeadline;
        }

        $firstMoveCap = $anchor->modify('+'.MultiplayerLimits::FIRST_MOVE_TIMEOUT_SECONDS.' seconds');

        if (null === $rawDeadline) {
            return $firstMoveCap;
        }

        return $rawDeadline < $firstMoveCap ? $rawDeadline : $firstMoveCap;
    }

    private function microsToDateTime(int $micros): \DateTimeImmutable
    {
        $seconds = intdiv($micros, 1_000_000);
        $microRemainder = $micros - $seconds * 1_000_000;

        return (new \DateTimeImmutable('@'.$seconds))->modify(\sprintf('+%d microseconds', $microRemainder));
    }
}
