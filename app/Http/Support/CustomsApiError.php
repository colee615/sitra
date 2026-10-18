<?php

namespace App\Http\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/** Builds stable, non-sensitive error responses for the Customs API. */
final class CustomsApiError
{
    public static function response(
        int $status,
        string $message,
        array $details = [],
        array $headers = [],
        ?string $requestId = null,
    ): JsonResponse {
        $requestId ??= (string) Str::uuid();
        $body = [
            'message' => $message,
            'error' => [
                'code' => self::codeForStatus($status),
                'request_id' => $requestId,
                'retryable' => in_array($status, [429, 503], true),
            ],
        ];

        if ($details !== []) {
            $body['errors'] = $details;
        }

        return response()->json($body, $status, array_merge($headers, [
            'Cache-Control' => 'no-store',
            'X-Request-ID' => $requestId,
        ]));
    }

    public static function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            422 => 'VALIDATION_ERROR',
            429 => 'RATE_LIMITED',
            503 => 'DEPENDENCY_UNAVAILABLE',
            default => 'INTERNAL_ERROR',
        };
    }
}
