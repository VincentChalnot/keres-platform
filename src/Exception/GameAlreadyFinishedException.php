<?php

declare(strict_types=1);

namespace App\Exception;

/** Post-lock re-check found `gameOverAt` already set - 409 `game_finished` (03-time-control.md sec 6.6). */
class GameAlreadyFinishedException extends \RuntimeException
{
}
