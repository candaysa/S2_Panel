<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Central JSON response helpers.
 *
 * Envelope: success -> { "data": ..., "meta": ... }
 *           failure -> { "message": "...", "errors": {...} }
 *
 * Messages are fixed English strings (not translated) so clients can rely
 * on stable identifiers.
 */
final class Api
{
    public const MSG_OK = 'ok';

    public const MSG_NOT_FOUND = 'not_found';

    public const MSG_FORBIDDEN = 'forbidden';

    public const MSG_UNAUTHENTICATED = 'unauthenticated';

    public const MSG_VALIDATION_FAILED = 'validation_failed';

    public const MSG_SERVER_ERROR = 'server_error';

    public const MSG_NOT_INSTALLED = 'not_installed';

    public const MSG_ALREADY_EXISTS = 'already_exists';

    public const MSG_INVALID_INPUT = 'invalid_input';

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(mixed $data = null, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => $meta,
        ]);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    public static function error(string $message, array $errors = [], int $status = 400): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }

    public static function notFound(string $message = self::MSG_NOT_FOUND): JsonResponse
    {
        return self::error($message, [], 404);
    }

    public static function forbidden(string $message = self::MSG_FORBIDDEN): JsonResponse
    {
        return self::error($message, [], 403);
    }

    /**
     * Clamp a client-supplied ?per_page= into a usable page size.
     *
     * Every index endpoint capped the upper end and left the lower end
     * open, so per_page=0 and per_page=-5 reached the paginator, which
     * treats them as "no limit" or throws depending on the driver - a
     * client-controlled way to ask for the whole table. Both ends are
     * clamped here so the ten callers cannot drift apart again.
     */
    public static function perPage(mixed $value, int $default = 25, int $max = 100): int
    {
        $perPage = is_numeric($value) ? (int) $value : $default;

        return max(1, min($perPage, $max));
    }

    public static function unauthenticated(string $message = self::MSG_UNAUTHENTICATED): JsonResponse
    {
        return self::error($message, [], 401);
    }
}