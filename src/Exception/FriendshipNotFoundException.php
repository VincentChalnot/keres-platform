<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * No row exists in the state the caller expects - 404 `friendship_not_found`
 * (09-api-reference.md sec 4.3, 6.4). Also the deliberate response for
 * "you are not a party to this row" (05-social.md's blocking discipline
 * extends here: existence is not disclosed to a non-party).
 */
class FriendshipNotFoundException extends \RuntimeException
{
}
