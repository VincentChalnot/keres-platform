<?php

declare(strict_types=1);

namespace App\Service\Game;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class GameUpdatePublisher
{
    /** SSE event name of an engine evaluation update (mirrored by `MercureClient.ts`). */
    public const EVALUATION_EVENT = 'evaluation';

    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public function publishGameState(string $gameUuid, string $json): void
    {
        $this->publish("game/{$gameUuid}", $json, false);
    }

    /**
     * One engine evaluation (`ply` = moves played, White's point of view).
     * Sent as a named SSE event so it never reaches the `onmessage` handler
     * that expects a full game state; the caller is responsible for the
     * `Game::canExposeEvaluation()` gate (the topic is open to spectators).
     */
    public function publishEvaluation(string $gameUuid, int $ply, int $evaluation): void
    {
        $this->publish("game/{$gameUuid}", json_encode(['ply' => $ply, 'evaluation' => $evaluation], \JSON_THROW_ON_ERROR), false, self::EVALUATION_EVENT);
    }

    public function publishUserEvent(string $userUuid, string $json): void
    {
        $this->publish("user/{$userUuid}", $json, true);
    }

    public function publishSeekEvent(string $json): void
    {
        $this->publish('lobby/seeks', $json, false);
    }

    private function publish(string $topic, string $json, bool $private, ?string $type = null): void
    {
        try {
            $this->hub->publish(new Update($topic, $json, $private, null, $type));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to publish Mercure update to {topic}: {message}', [
                'topic' => $topic,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
