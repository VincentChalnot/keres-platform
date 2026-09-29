<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Game;
use App\Entity\User;
use App\Message\RecordAnalyticsEventMessage;
use App\Model\AnalyticsEventType;
use App\Model\OpponentType;
use App\Model\PieceColor;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * T6: thin facade over `RecordAnalyticsEventMessage` dispatch, same
 * "one place, consistent shape" reasoning as `NotificationCenter` for
 * notifications. Every call site gets a single cheap method call - no
 * query, no join, no wait for a flush; the message itself carries the
 * timestamp captured here at call time (see the message's own docblock).
 */
final readonly class AnalyticsRecorder
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function accountCreated(User $user): void
    {
        $this->dispatch(AnalyticsEventType::ACCOUNT_CREATED, userId: $user->getId());
    }

    public function firstGameStarted(User $user, Game $game): void
    {
        $this->dispatch(AnalyticsEventType::FIRST_GAME_STARTED, userId: $user->getId(), game: $game->getUuid());
    }

    /**
     * `$aiLevel` stays present-but-null until T10 (AI difficulty levels)
     * lands - the payload shape doesn't need to change again then.
     */
    public function gameStarted(User $user, Game $game, OpponentType $opponentType, ?int $aiLevel = null): void
    {
        $this->dispatch(AnalyticsEventType::GAME_STARTED, userId: $user->getId(), game: $game->getUuid(), payload: [
            'opponentType' => $opponentType->name,
            'aiLevel' => $aiLevel,
        ]);
    }

    public function movePlayed(Game $game, PieceColor $mover): void
    {
        $this->dispatch(AnalyticsEventType::MOVE_PLAYED, game: $game->getUuid(), payload: [
            'mover' => $mover->name,
        ]);
    }

    /** Reads the result straight off `$game` - call after `Game::finish()`, never before. */
    public function gameFinished(Game $game): void
    {
        $this->dispatch(AnalyticsEventType::GAME_FINISHED, game: $game->getUuid(), payload: [
            'reason' => $game->getEndReason()->name,
            'whiteWins' => $game->isWhiteWins(),
            'draw' => $game->isDraw(),
        ]);
    }

    public function gameAbandoned(Game $game): void
    {
        $this->dispatch(AnalyticsEventType::GAME_ABANDONED, game: $game->getUuid());
    }

    /** @param array<string, mixed> $payload */
    private function dispatch(AnalyticsEventType $type, ?Uuid $userId = null, ?Uuid $game = null, array $payload = []): void
    {
        $this->messageBus->dispatch(new RecordAnalyticsEventMessage(
            $type,
            (new \DateTimeImmutable())->format(\DATE_ATOM),
            $userId?->toRfc4122(),
            $game?->toRfc4122(),
            $payload,
        ));
    }
}
