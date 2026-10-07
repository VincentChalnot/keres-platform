<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PlayBotGameMessage;
use App\Model\TimeControl;
use App\Service\BotTournament\BotGamePlayer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
readonly class PlayBotGameHandler
{
    public function __construct(
        private BotGamePlayer $botGamePlayer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(PlayBotGameMessage $message): void
    {
        try {
            $this->botGamePlayer->play(
                $message->whiteLevel,
                $message->blackLevel,
                TimeControl::realtime($message->initialSeconds, $message->incrementSeconds),
                $message->maxPlies,
            );
        } catch (\Throwable $e) {
            // Retrying would just start another game next to the aborted one:
            // park it on the failure transport instead.
            throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
        } finally {
            $this->entityManager->clear();
        }
    }
}
