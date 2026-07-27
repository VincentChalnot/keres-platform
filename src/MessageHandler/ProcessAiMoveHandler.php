<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Engine\GameEngine;
use App\Message\ProcessAiMoveMessage;
use App\Repository\GameRepository;
use App\Service\Game\GameStatePayloadBuilder;
use App\Service\Game\GameUpdatePublisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
readonly class ProcessAiMoveHandler
{
    public function __construct(
        private GameRepository $gameRepository,
        private GameEngine $gameEngine,
        private GameUpdatePublisher $gameUpdatePublisher,
        private GameStatePayloadBuilder $payloadBuilder,
    ) {
    }

    public function __invoke(ProcessAiMoveMessage $message): void
    {
        $game = $this->gameRepository->findByUuid(Uuid::fromString($message->gameUuid));

        if (!$game) {
            throw new \RuntimeException('Game not found: '.$message->gameUuid);
        }

        if ($game->getGameMoves()->count() !== $message->moveCounter) {
            $boardMovesData = $this->gameEngine->getBoardMovesData($game);
        } else {
            $boardMovesData = $this->gameEngine->aiMove($game, (int) (microtime(true) * 1_000_000));
        }

        $payload = $this->payloadBuilder->build($game, $boardMovesData);
        $json = $this->payloadBuilder->encode($payload);
        $this->gameUpdatePublisher->publishGameState(
            $game->getUuid()->toRfc4122(),
            $json
        );
    }
}
