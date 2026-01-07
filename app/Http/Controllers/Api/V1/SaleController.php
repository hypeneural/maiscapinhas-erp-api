<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    use ApiResponse;

    /**
     * List sales with filters.
     *
     * GET /api/v1/sales?store_id=&seller_id=&from=&to=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
            'seller_id' => ['sometimes', 'integer', 'exists:users,id'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $userStoreIds = $user->storeUsers()->pluck('store_id')->toArray();

        $query = Sale::with(['store:id,name', 'seller:id,name'])
            ->whereIn('store_id', $userStoreIds);

        // Filter by store
        if ($request->filled('store_id')) {
            $storeId = (int) $request->input('store_id');
            if (!in_array($storeId, $userStoreIds)) {
                return $this->forbidden('You do not have access to this store.');
            }
            $query->where('store_id', $storeId);
        }

        // Filter by seller
        if ($request->filled('seller_id')) {
            $query->where('seller_id', $request->input('seller_id'));
        }

        // Filter by date range
        if ($request->filled('from')) {
            $query->where('sold_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('sold_at', '<=', $request->input('to') . ' 23:59:59');
        }

        $perPage = $request->input('per_page', 25);
        $paginator = $query->orderByDesc('sold_at')->paginate($perPage);

        return $this->paginated($paginator);
    }
}
