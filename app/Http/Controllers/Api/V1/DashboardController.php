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

/**
 * @group Dashboards
 *
 * Endpoints de dashboard com métricas e KPIs por perfil de usuário.
 * Cada dashboard retorna informações personalizadas para o papel do usuário.
 */
class DashboardController extends Controller
{
    use ApiResponse;

    /**
     * Dashboard do Vendedor
     *
     * Retorna métricas do vendedor no dia, incluindo suas vendas pessoais
     * comparadas com o total da loja e status dos seus turnos.
     *
     * **Quem pode usar:** Vendedores e níveis superiores.
     *
     * **Métricas retornadas:**
     * - `my_sales` - Quantidade e valor total das vendas do vendedor no dia
     * - `store_sales` - Quantidade e valor total de todas as vendas da loja no dia
     * - `my_shifts` - Lista dos turnos do vendedor naquele dia com status do fechamento
     *
     * **Dica:** Compare `my_sales` com `store_sales` para ver sua participação nas vendas da loja.
     *
     * @queryParam store_id integer required ID da loja. Example: 1
     * @queryParam date string Data no formato YYYY-MM-DD (padrão: hoje). Example: 2026-01-07
     *
     * @response 200 scenario="Vendas do dia" {
     *   "data": {
     *     "date": "2026-01-07",
     *     "my_sales": { "count": 5, "total": 850.00 },
     *     "store_sales": { "count": 23, "total": 3200.00 },
     *     "my_shifts": [
     *       { "id": 1, "date": "2026-01-07", "shift_code": "M", "status": "open", "cash_closing": null }
     *     ]
     *   },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     *
     * @response 403 scenario="Sem acesso à loja" {
     *   "error": { "code": 403, "message": "You do not have access to this store." }
     * }
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

        $mySales = Sale::where('store_id', $storeId)
            ->where('seller_id', $user->id)
            ->whereDate('sold_at', $date)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as total')
            ->first();

        $storeSales = Sale::where('store_id', $storeId)
            ->whereDate('sold_at', $date)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as total')
            ->first();

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
     * Dashboard do Conferente
     *
     * Retorna métricas para conferência de caixa, incluindo fechamentos
     * pendentes de aprovação e ranking de vendedores.
     *
     * **Quem pode usar:** Conferentes, Gerentes e Admins.
     *
     * **Métricas retornadas:**
     * - `pending_closings` - Lista de fechamentos aguardando aprovação/rejeição
     * - `pending_count` - Quantidade de fechamentos pendentes
     * - `store_sales` - Total de vendas da loja no dia
     * - `shifts_today` - Resumo de turnos por status (open, closed, pending)
     * - `top_sellers` - Top 5 vendedores do dia por valor vendido
     *
     * @queryParam store_id integer required ID da loja. Example: 1
     * @queryParam date string Data no formato YYYY-MM-DD (padrão: hoje). Example: 2026-01-07
     *
     * @response 200 scenario="Dashboard do conferente" {
     *   "data": {
     *     "date": "2026-01-07",
     *     "pending_closings": [],
     *     "pending_count": 0,
     *     "store_sales": { "count": 23, "total": 3200.00 },
     *     "shifts_today": { "open": 2, "closed": 4 },
     *     "top_sellers": [
     *       { "seller_id": 6, "name": "João Vendedor", "total": 1500.00 }
     *     ]
     *   },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
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

        $pendingClosings = CashClosing::whereHas('cashShift', function ($q) use ($storeId) {
            $q->where('store_id', $storeId);
        })
            ->where('status', CashClosing::STATUS_SUBMITTED)
            ->with(['cashShift:id,date,shift_code,seller_id,store_id', 'cashShift.seller:id,name'])
            ->get();

        $storeSales = Sale::where('store_id', $storeId)
            ->whereDate('sold_at', $date)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as total')
            ->first();

        $shiftsToday = CashShift::where('store_id', $storeId)
            ->where('date', $date)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

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
     * Dashboard do Admin
     *
     * Retorna visão consolidada de todas as lojas que o usuário administra,
     * com métricas mensais agregadas.
     *
     * **Quem pode usar:** Admins e Gerentes.
     *
     * **Métricas retornadas:**
     * - `total_sales` - Soma de vendas de todas as lojas no mês
     * - `sales_by_store` - Vendas detalhadas por loja
     * - `closings_summary` - Contagem de fechamentos por status
     * - `top_sellers` - Top 10 vendedores do mês (todas as lojas)
     *
     * @queryParam month string Mês no formato YYYY-MM (padrão: mês atual). Example: 2026-01
     *
     * @response 200 scenario="Dashboard consolidado" {
     *   "data": {
     *     "month": "2026-01",
     *     "total_sales": { "count": 450, "total": 67500.00 },
     *     "sales_by_store": [
     *       { "store_id": 1, "store_name": "Mais Capinhas Tijucas", "count": 180, "total": 28000.00 }
     *     ],
     *     "closings_summary": { "approved": 40, "submitted": 5, "draft": 3 },
     *     "top_sellers": [
     *       { "seller_id": 6, "name": "João Vendedor", "total": 12500.00, "count": 85 }
     *     ]
     *   },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     */
    public function admin(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $user = $request->user();
        $month = $request->input('month', Carbon::now()->format('Y-m'));

        $userStoreIds = $user->storeUsers()
            ->whereIn('role', ['admin', 'gerente'])
            ->pluck('store_id')
            ->toArray();

        if (empty($userStoreIds)) {
            return $this->forbidden('You do not have admin access to any store.');
        }

        $startOfMonth = Carbon::parse($month . '-01')->startOfMonth();
        $endOfMonth = Carbon::parse($month . '-01')->endOfMonth();

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

        $totalSales = Sale::whereIn('store_id', $userStoreIds)
            ->whereBetween('sold_at', [$startOfMonth, $endOfMonth])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as total')
            ->first();

        $closingsSummary = CashClosing::whereHas('cashShift', function ($q) use ($userStoreIds, $startOfMonth, $endOfMonth) {
            $q->whereIn('store_id', $userStoreIds)
                ->whereBetween('date', [$startOfMonth->format('Y-m-d'), $endOfMonth->format('Y-m-d')]);
        })
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

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
