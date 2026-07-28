<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The closed set of machine-readable error codes a JSON endpoint may return
 * (09-api-reference.md sec 2.2/2.5/9). Grown incrementally as each phase's
 * endpoints land - this is the Phase 3 (matchmaking) subset plus the small
 * set of cross-cutting codes every JSON action needs. `httpStatus()` is the
 * only place a status is chosen, so no call site can invent one.
 */
enum ApiErrorCode: string
{
    public function httpStatus(): int
    {
        return match ($this) {
            self::AUTHENTICATION_REQUIRED => 401,
            self::FORBIDDEN => 403,
            self::NOT_FOUND, self::SEEK_NOT_FOUND => 404,
            self::VALIDATION_FAILED, self::UNRATED_TIME_CONTROL, self::INVALID_TIME_CONTROL => 422,
            self::MALFORMED_JSON => 400,
            self::RATE_LIMITED => 429,
            self::SEEK_UNAVAILABLE, self::SEEK_ALREADY_MATCHED, self::RATING_OUT_OF_RANGE, self::CANNOT_ACCEPT_OWN_SEEK => 409,
            self::SEEK_EXPIRED => 410,
            self::INTERNAL_ERROR => 500,
        };
    }
    case AUTHENTICATION_REQUIRED = 'authentication_required';
    case FORBIDDEN = 'forbidden';
    case NOT_FOUND = 'not_found';
    case VALIDATION_FAILED = 'validation_failed';
    case MALFORMED_JSON = 'malformed_json';
    case RATE_LIMITED = 'rate_limited';
    case INTERNAL_ERROR = 'internal_error';

    case UNRATED_TIME_CONTROL = 'unrated_time_control';
    case INVALID_TIME_CONTROL = 'invalid_time_control';

    case SEEK_NOT_FOUND = 'seek_not_found';
    case SEEK_UNAVAILABLE = 'seek_unavailable';
    case SEEK_EXPIRED = 'seek_expired';
    case SEEK_ALREADY_MATCHED = 'seek_already_matched';
    case CANNOT_ACCEPT_OWN_SEEK = 'cannot_accept_own_seek';
    case RATING_OUT_OF_RANGE = 'rating_out_of_range';
}
