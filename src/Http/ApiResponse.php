<?php

declare(strict_types=1);

namespace App\Http;

use App\Model\ApiErrorCode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * The one JSON envelope every `/lobby/*` (and later) endpoint uses
 * (09-api-reference.md sec 2.2). `data` is required and is an object, an
 * array, or null - never a scalar. There is no `success` boolean: HTTP
 * status carries that.
 */
final readonly class ApiResponse
{
    private const int ENCODE_FLAGS = \JSON_THROW_ON_ERROR
        | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE;

    /** @param array<string, mixed>|list<mixed>|null $data */
    public static function ok(?array $data, ?array $meta = null): JsonResponse
    {
        $body = ['data' => $data];

        if (null !== $meta) {
            $body['meta'] = $meta;
        }

        return new JsonResponse($body, 200, [], false, self::ENCODE_FLAGS);
    }

    /** @param array<string, mixed> $data */
    public static function created(array $data): JsonResponse
    {
        return new JsonResponse(['data' => $data], 201, [], false, self::ENCODE_FLAGS);
    }

    /** @param array<string, mixed>|null $details */
    public static function error(ApiErrorCode $code, string $message, ?array $details = null): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code->value,
                'message' => $message,
                'details' => $details,
            ],
        ], $code->httpStatus(), [], false, self::ENCODE_FLAGS);
    }

    public static function validation(ConstraintViolationListInterface $violations): JsonResponse
    {
        $list = [];

        foreach ($violations as $violation) {
            $field = trim((string) preg_replace('/^\[(.+)]$/', '$1', $violation->getPropertyPath()));
            $constraintClass = $violation->getConstraint();
            $shortName = null !== $constraintClass ? (new \ReflectionClass($constraintClass))->getShortName() : 'invalid';
            $constraint = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));

            $list[] = [
                'field' => $field,
                'constraint' => $constraint,
                'message' => (string) $violation->getMessage(),
            ];
        }

        return self::error(ApiErrorCode::VALIDATION_FAILED, 'Request payload failed validation.', ['violations' => $list]);
    }
}
