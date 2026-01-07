<?php

declare(strict_types=1);

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

trait ApiResponse
{
    /**
     * Return a success response with data.
     */
    protected function success(mixed $data = null, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => $this->getMeta(),
        ], $status, $headers);
    }

    /**
     * Return a paginated response.
     */
    protected function paginated($paginator, $resource = null): JsonResponse
    {
        $items = $resource
            ? $resource::collection($paginator->items())
            : $paginator->items();

        return response()->json([
            'data' => $items,
            'meta' => array_merge($this->getMeta(), [
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ]),
        ]);
    }

    /**
     * Return a created response.
     */
    protected function created(mixed $data = null): JsonResponse
    {
        return $this->success($data, 201);
    }

    /**
     * Return a no content response.
     */
    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * Return an error response.
     */
    protected function error(string $message, int $status = 400, array $errors = []): JsonResponse
    {
        $response = [
            'message' => $message,
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $status);
    }

    /**
     * Return a validation error response.
     */
    protected function validationError(array $errors): JsonResponse
    {
        return $this->error('Validation failed.', 422, $errors);
    }

    /**
     * Return an unauthorized response.
     */
    protected function unauthorized(string $message = 'Unauthenticated.'): JsonResponse
    {
        return $this->error($message, 401);
    }

    /**
     * Return a forbidden response.
     */
    protected function forbidden(string $message = 'Forbidden.'): JsonResponse
    {
        return $this->error($message, 403);
    }

    /**
     * Return a not found response.
     */
    protected function notFound(string $message = 'Resource not found.'): JsonResponse
    {
        return $this->error($message, 404);
    }

    /**
     * Return a conflict response.
     */
    protected function conflict(string $message): JsonResponse
    {
        return $this->error($message, 409);
    }

    /**
     * Get standard meta information.
     */
    private function getMeta(): array
    {
        return [
            'request_id' => (string) Str::uuid(),
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
