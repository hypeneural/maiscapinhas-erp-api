<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CashShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashShiftController extends Controller
{
    use ApiResponse;

    /**
     * List cash shifts with filters.
     *
     * GET /api/v1/cash/shifts?store_id=&date=&status=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
            'date' => ['sometimes', 'date'],
            'status' => ['sometimes', 'string', 'in:open,closed,pending'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $userStoreIds = $user->storeUsers()->pluck('store_id')->toArray();

        $query = CashShift::with(['store:id,name', 'seller:id,name', 'cashClosing:id,cash_shift_id,status'])
            ->whereIn('store_id', $userStoreIds);

        if ($request->filled('store_id')) {
            $storeId = (int) $request->input('store_id');
            if (!in_array($storeId, $userStoreIds)) {
                return $this->forbidden('You do not have access to this store.');
            }
            $query->where('store_id', $storeId);
        }

        if ($request->filled('date')) {
            $query->where('date', $request->input('date'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = $request->input('per_page', 25);
        $paginator = $query->orderByDesc('date')->orderBy('shift_code')->paginate($perPage);

        return $this->paginated($paginator);
    }

    /**
     * Create a new cash shift.
     *
     * POST /api/v1/cash/shifts
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'date' => ['required', 'date'],
            'shift_code' => ['required', 'string', 'in:M,T,N'],
            'seller_id' => ['sometimes', 'integer', 'exists:users,id'],
        ]);

        $user = $request->user();
        $storeId = (int) $request->input('store_id');

        if (!$user->hasAccessToStore($storeId)) {
            return $this->forbidden('You do not have access to this store.');
        }

        // Default seller to current user if not specified
        $sellerId = $request->input('seller_id', $user->id);

        $shift = CashShift::create([
            'store_id' => $storeId,
            'date' => $request->input('date'),
            'shift_code' => $request->input('shift_code'),
            'seller_id' => $sellerId,
            'status' => CashShift::STATUS_OPEN,
        ]);

        return $this->created($shift->load(['store:id,name', 'seller:id,name']));
    }

    /**
     * Get a specific cash shift.
     *
     * GET /api/v1/cash/shifts/{shift}
     */
    public function show(Request $request, CashShift $shift): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToStore($shift->store_id)) {
            return $this->forbidden('You do not have access to this store.');
        }

        return $this->success(
            $shift->load(['store:id,name', 'seller:id,name', 'cashClosing.lines'])
        );
    }
}
