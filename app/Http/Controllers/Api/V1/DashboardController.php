<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CashClosing;
use App\Models\CashShift;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use ApiResponse;

    /**
     * Dashboard for vendedor (seller).
     *
     * GET /api/v1/dashboard/vendedor?store_id=&date=
     */
    public function vendedor(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'date' => ['sometimes', 'date'],
        ]);

        $user = $request->user();
        $storeId = (int) $request->input('store_id');
        $date = $request->input('date', Carbon::today()->format('Y-m-d'));

        if (!$user->hasAccessToStore($storeId)) {
            return $this->forbidden('You do not have access to this store.');
        }

        // Today's sales for this seller
        $mySales = Sale::where('store_id', $storeId)
            ->where('seller_id', $user->id)
            ->whereDate('sold_at', $date)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as total')
            ->first();

        // Store total for comparison
        $storeSales = Sale::where('store_id', $storeId)
            ->whereDate('sold_at', $date)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as total')
            ->first();

        // My open shifts today
        $myShifts = CashShift::where('store_id', $storeId)
            ->where('seller_id', $user->id)
            ->where('date', $date)
            ->with('cashClosing:id,cash_shift_id,status')
            ->get();

        return $this->success([
            'date' => $date,
            'my_sales' => [
                'count' => (int) $mySales->count,
                'total' => (float) $mySales->total,
            ],
            'store_sales' => [
                'count' => (int) $storeSales->count,
                'total' => (float) $storeSales->total,
            ],
            'my_shifts' => $myShifts,
        ]);
    }

    /**
     * Dashboard for conferente (reviewer).
     *
     * GET /api/v1/dashboard/conferente?store_id=&date=
     */
    public function conferente(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'date' => ['sometimes', 'date'],
        ]);

        $user = $request->user();
        $storeId = (int) $request->input('store_id');
        $date = $request->input('date', Carbon::today()->format('Y-m-d'));

        if (!$user->hasAccessToStore($storeId)) {
            return $this->forbidden('You do not have access to this store.');
        }

        // Pending closings for review
        $pendingClosings = CashClosing::whereHas('cashShift', function ($q) use ($storeId) {
            $q->where('store_id', $storeId);
        })
            ->where('status', CashClosing::STATUS_SUBMITTED)
            ->with(['cashShift:id,date,shift_code,seller_id,store_id', 'cashShift.seller:id,name'])
            ->get();

        // Today's store summary
        $storeSales = Sale::where('store_id', $storeId)
            ->whereDate('sold_at', $date)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as total')
            ->first();

        // Shifts summary for today
        $shiftsToday = CashShift::where('store_id', $storeId)
            ->where('date', $date)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        // Top sellers today
        $topSellers = Sale::where('store_id', $storeId)
            ->whereDate('sold_at', $date)
            ->selectRaw('seller_id, SUM(amount) as total')
            ->groupBy('seller_id')
            ->orderByDesc('total')
            ->limit(5)
            ->with('seller:id,name')
            ->get()
            ->map(fn($s) => [
                'seller_id' => $s->seller_id,
                'name' => $s->seller->name,
                'total' => (float) $s->total,
            ]);

        return $this->success([
            'date' => $date,
            'pending_closings' => $pendingClosings,
            'pending_count' => $pendingClosings->count(),
            'store_sales' => [
                'count' => (int) $storeSales->count,
                'total' => (float) $storeSales->total,
            ],
            'shifts_today' => $shiftsToday,
            'top_sellers' => $topSellers,
        ]);
    }

    /**
     * Dashboard for admin.
     *
     * GET /api/v1/dashboard/admin?month=
     */
    public function admin(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $user = $request->user();
        $month = $request->input('month', Carbon::now()->format('Y-m'));

        // Get stores the user has admin access to
        $userStoreIds = $user->storeUsers()
            ->whereIn('role', ['admin', 'gerente'])
            ->pluck('store_id')
            ->toArray();

        if (empty($userStoreIds)) {
            return $this->forbidden('You do not have admin access to any store.');
        }

        $startOfMonth = Carbon::parse($month . '-01')->startOfMonth();
        $endOfMonth = Carbon::parse($month . '-01')->endOfMonth();

        // Sales by store
        $salesByStore = Sale::whereIn('store_id', $userStoreIds)
            ->whereBetween('sold_at', [$startOfMonth, $endOfMonth])
            ->selectRaw('store_id, COUNT(*) as count, COALESCE(SUM(amount), 0) as total')
            ->groupBy('store_id')
            ->with('store:id,name')
            ->get()
            ->map(fn($s) => [
                'store_id' => $s->store_id,
                'store_name' => $s->store->name,
                'count' => (int) $s->count,
                'total' => (float) $s->total,
            ]);

        // Total sales
        $totalSales = Sale::whereIn('store_id', $userStoreIds)
            ->whereBetween('sold_at', [$startOfMonth, $endOfMonth])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as total')
            ->first();

        // Closings summary
        $closingsSummary = CashClosing::whereHas('cashShift', function ($q) use ($userStoreIds, $startOfMonth, $endOfMonth) {
            $q->whereIn('store_id', $userStoreIds)
                ->whereBetween('date', [$startOfMonth->format('Y-m-d'), $endOfMonth->format('Y-m-d')]);
        })
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        // Top 10 sellers across all stores
        $topSellers = Sale::whereIn('store_id', $userStoreIds)
            ->whereBetween('sold_at', [$startOfMonth, $endOfMonth])
            ->selectRaw('seller_id, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('seller_id')
            ->orderByDesc('total')
            ->limit(10)
            ->with('seller:id,name')
            ->get()
            ->map(fn($s) => [
                'seller_id' => $s->seller_id,
                'name' => $s->seller->name,
                'total' => (float) $s->total,
                'count' => (int) $s->count,
            ]);

        return $this->success([
            'month' => $month,
            'total_sales' => [
                'count' => (int) $totalSales->count,
                'total' => (float) $totalSales->total,
            ],
            'sales_by_store' => $salesByStore,
            'closings_summary' => $closingsSummary,
            'top_sellers' => $topSellers,
        ]);
    }
}
