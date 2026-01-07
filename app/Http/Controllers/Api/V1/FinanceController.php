<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\StoreUserRole;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\SellerDailyBonus;
use App\Models\SellerMonthlyCommission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Extrato Financeiro
 *
 * Endpoints para consultar o extrato de bônus diário e comissão mensal.
 * Vendedores veem apenas seus próprios dados; gerentes/admins veem todos.
 */
class FinanceController extends Controller
{
    use ApiResponse;

    /**
     * Get bonus ledger.
     *
     * GET /api/v1/finance/bonus?store_id=&from=&to=
     */
    public function bonus(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $storeIds = $user->storeUsers()->pluck('store_id')->toArray();

        $query = SellerDailyBonus::with(['store:id,name', 'user:id,name'])
            ->whereIn('store_id', $storeIds);

        // Filter by store
        if ($request->filled('store_id')) {
            $storeId = (int) $request->input('store_id');
            if (!in_array($storeId, $storeIds)) {
                return $this->forbidden('You do not have access to this store.');
            }
            $query->where('store_id', $storeId);
        }

        // For vendedor, only show their own bonuses
        $isManager = $user->storeUsers()
            ->whereIn('role', [StoreUserRole::ADMIN->value, StoreUserRole::GERENTE->value, StoreUserRole::CONFERENTE->value])
            ->exists();

        if (!$isManager) {
            $query->where('user_id', $user->id);
        }

        // Date range
        if ($request->filled('from')) {
            $query->where('date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('date', '<=', $request->input('to'));
        }

        $bonuses = $query->orderByDesc('date')->paginate($request->input('per_page', 25));

        return $this->paginated($bonuses);
    }

    /**
     * Get commission ledger.
     *
     * GET /api/v1/finance/commission?store_id=&month=
     */
    public function commission(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $storeIds = $user->storeUsers()->pluck('store_id')->toArray();

        $query = SellerMonthlyCommission::with(['store:id,name', 'user:id,name'])
            ->whereIn('store_id', $storeIds);

        // Filter by store
        if ($request->filled('store_id')) {
            $storeId = (int) $request->input('store_id');
            if (!in_array($storeId, $storeIds)) {
                return $this->forbidden('You do not have access to this store.');
            }
            $query->where('store_id', $storeId);
        }

        // For vendedor, only show their own commissions
        $isManager = $user->storeUsers()
            ->whereIn('role', [StoreUserRole::ADMIN->value, StoreUserRole::GERENTE->value, StoreUserRole::CONFERENTE->value])
            ->exists();

        if (!$isManager) {
            $query->where('user_id', $user->id);
        }

        // Month filter
        if ($request->filled('month')) {
            $query->where('month', $request->input('month'));
        }

        $commissions = $query->orderByDesc('month')->paginate($request->input('per_page', 25));

        return $this->paginated($commissions);
    }
}
