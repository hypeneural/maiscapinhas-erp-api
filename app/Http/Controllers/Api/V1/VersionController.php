<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * API version information endpoint.
 */
class VersionController extends Controller
{
    /**
     * Return API version information.
     *
     * @return JsonResponse
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'name' => config('app.name'),
                'api' => 'v1',
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
            ],
        ]);
    }
}
