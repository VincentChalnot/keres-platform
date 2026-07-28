<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The move was rejected because the mover's clock had already run out
 * (03-time-control.md sec 4.1 step 13). The game is already finalised and
 * committed by the time this is thrown - 409 `flagged` + the finished state.
 */
class MoveFlaggedException extends \RuntimeException
{
}
