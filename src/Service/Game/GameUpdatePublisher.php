<?php

declare(strict_types=1);

namespace App\Service\Game;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class GameUpdatePublisher
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public function publishGameState(string $gameUuid, string $json): void
    {
        $this->publish("game/{$gameUuid}", $json, false);
    }

    public function publishUserEvent(string $userUuid, string $json): void
    {
        $this->publish("user/{$userUuid}", $json, true);
    }

    public function publishSeekEvent(string $json): void
    {
        $this->publish('lobby/seeks', $json, false);
    }

    private function publish(string $topic, string $json, bool $private): void
    {
        try {
            $this->hub->publish(new Update($topic, $json, $private));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to publish Mercure update to {topic}: {message}', [
                'topic' => $topic,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
