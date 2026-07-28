<?php

declare(strict_types=1);

namespace App\Exception;

/** An `ACCEPTED` friendship already exists between the pair - 409 `friendship_exists` (09-api-reference.md sec 4.3). */
class FriendshipExistsException extends \RuntimeException
{
}
