<?php

declare(strict_types=1);

namespace App\Exception;

/** `POST /friends/request` targeting the caller - 422 `cannot_request_self` (05-social.md sec 3.3 T1 guard, 09-api-reference.md sec 4.3). */
class CannotRequestSelfException extends \RuntimeException
{
}
