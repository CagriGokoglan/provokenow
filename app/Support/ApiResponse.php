<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Standard envelope for every API response:
 * { "success": bool, "data": mixed, "message": string, "errors"?: object }
 */
final class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
        ], $status);
    }

    public static function error(string $message, int $status = 400, mixed $errors = null): JsonResponse
    {
        $body = [
            'success' => false,
            'data' => null,
            'message' => $message,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status);
    }
}
