<?php

declare(strict_types=1);

namespace App\Message;

/**
 * One bot-vs-bot game of the tournament started by `app:bot-tournament`,
 * played to completion by PlayBotGameHandler on the async transport.
 */
readonly class PlayBotGameMessage
{
    public function __construct(
        public int $whiteLevel,
        public int $blackLevel,
        public int $initialSeconds,
        public int $incrementSeconds,
        public int $maxPlies,
    ) {
    }
}
