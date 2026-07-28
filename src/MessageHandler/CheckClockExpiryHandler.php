<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CheckClockExpiryMessage;
use App\Repository\GameRepository;
use App\Service\Game\ClockAdjudicator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Path (a) of the three flag-adjudication triggers (03-time-control.md
 * sec 5.2/5.3). A stale message is a success, not a failure: the handler
 * returns normally so the transport acks with no retry and no `failed` row.
 */
#[AsMessageHandler]
readonly class CheckClockExpiryHandler
{
    public function __construct(
        private GameRepository $gameRepository,
        private ClockAdjudicator $clockAdjudicator,
    ) {
    }

    public function __invoke(CheckClockExpiryMessage $message): void
    {
        $game = $this->gameRepository->findByUuid(Uuid::fromString($message->gameUuid));

        if (null === $game) {
            return; // deleted or never existed - drop
        }

        if ($game->getGameMoves()->count() !== $message->expectedMoveCount) {
            return; // a newer message (or the player's own move) governs
        }

        $deadline = $game->getMoveDeadlineAt();

        if (null === $deadline || (int) $deadline->format('Uu') !== $message->deadlineAtMicros) {
            return; // the deadline moved - re-armed, or already resolved
        }

        $this->clockAdjudicator->adjudicate($game);
    }
}
