<?php

declare(strict_types=1);

namespace App\Exception;

/** `POST /friends/block` targeting the caller - 422 `cannot_block_self` (05-social.md sec 4.1). */
class CannotBlockSelfException extends \RuntimeException
{
}
