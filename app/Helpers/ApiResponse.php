<?php

namespace App\Helpers;

/**
 * One consistent JSON envelope for every endpoint in this API — matches
 * the {status, message, data} shape the main ClearCutOff Laravel backend
 * already uses (App\Helpers\ApiResponse there), so a frontend consuming
 * both APIs sees one response shape either way. Only the handful of
 * status codes this read-only API actually returns — not that backend's
 * full 1xx–5xx catalogue, which would be dead code here.
 */
class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'Success', int $status = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function error(string $message = 'Error', int $status = 400, mixed $data = null)
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function notFound(string $message = 'Not Found')
    {
        return self::error($message, 404);
    }
}
