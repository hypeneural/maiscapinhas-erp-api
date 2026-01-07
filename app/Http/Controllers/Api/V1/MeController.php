<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    use ApiResponse;

    /**
     * Get the authenticated user with their stores and roles.
     *
     * GET /api/v1/me
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'active' => $user->active,
                'created_at' => $user->created_at->toIso8601String(),
            ],
            'stores' => $user->getStoresWithRoles(),
        ]);
    }
}
