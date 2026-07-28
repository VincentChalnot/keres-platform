<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The caller has blocked the target - 403 `blocked` (05-social.md sec 4.2).
 * Raised only when the *caller* is the blocker; the reverse direction (the
 * target blocked the caller) is silent by design and never throws (sec 4.3).
 */
class FriendshipBlockedException extends \RuntimeException
{
}
