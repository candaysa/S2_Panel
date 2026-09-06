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

    public static function unauthenticated(string $message = self::MSG_UNAUTHENTICATED): JsonResponse
    {
        return self::error($message, [], 401);
    }
}