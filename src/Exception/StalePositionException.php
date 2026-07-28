<?php

declare(strict_types=1);

namespace App\Exception;

/** Post-lock move-count mismatch - 409 `not_your_turn` (03-time-control.md sec 6.6). */
class StalePositionException extends \RuntimeException
{
}
