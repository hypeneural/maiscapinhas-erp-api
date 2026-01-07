<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    use ApiResponse;

    /**
     * List stores the authenticated user has access to.
     *
     * GET /api/v1/stores
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $stores = Store::whereIn('id', $user->storeUsers()->pluck('store_id'))
            ->where('active', true)
            ->get()
            ->map(fn(Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'city' => $store->city,
                'role' => $user->roleInStore($store->id),
            ]);

        return $this->success($stores);
    }

    /**
     * Get a specific store (if user has access).
     *
     * GET /api/v1/stores/{store}
     */
    public function show(Request $request, Store $store): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToStore($store->id)) {
            return $this->forbidden('You do not have access to this store.');
        }

        return $this->success([
            'id' => $store->id,
            'name' => $store->name,
            'city' => $store->city,
            'active' => $store->active,
            'role' => $user->roleInStore($store->id),
            'created_at' => $store->created_at->toIso8601String(),
        ]);
    }
}
