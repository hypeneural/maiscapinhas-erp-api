<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Health check endpoint for monitoring and container orchestration.
 */
class HealthController extends Controller
{
    /**
     * Return system health status.
     *
     * @return JsonResponse
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'status' => 'ok',
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }
}
