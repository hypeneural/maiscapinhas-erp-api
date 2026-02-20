<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Reports\Services\SellerGamificationService;
use App\Http\Controllers\Api\V1\Concerns\ResolvesReportFilters;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CashClosing;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Dashboards
 *
 * Endpoints de dashboard com métricas e KPIs personalizados por perfil de usuário.
 *
 * Cada dashboard retorna informações otimizadas para o papel do usuário:
 * - **Vendedor**: Foco em gamificação, bônus e comissão pessoal
 * - **Conferente**: Foco em fechamentos pendentes e integridade de caixa
 * - **Admin/Gerente**: Visão consolidada de todas as lojas
 *
 * **Regras de Acesso:**
 * - Usuário só vê dados das lojas às quais tem acesso
 * - Dashboard específico depende da role do usuário na loja
 */
class DashboardController extends Controller
{
    use ApiResponse;
    use ResolvesReportFilters;

    public function __construct(
        private SellerGamificationService $gamificationService
    ) {
    }

    /**
     * Dashboard do Vendedor
     *
     * Retorna métricas completas para o vendedor, incluindo:
     * - Vendas pessoais do dia
     * - **Gamificação de Bônus** - quanto falta para o próximo nível
     * - **Projeção de Comissão** - tier atual e potencial
     * - **Pace Diário** - ritmo comparado à média
     *
     * Este dashboard é projetado para **motivar** o vendedor mostrando
     * metas tangíveis e progresso em tempo real.
     *
     * **Quem pode usar:** Vendedores e níveis superiores.
     *
     * **Métricas retornadas:**
     * | Métrica | Descrição |
     * |---------|-----------|
     * | `my_sales` | Quantidade e valor total das vendas do dia |
     * | `store_sales` | Total de vendas da loja (para comparação) |
     * | `bonus_gamification` | Gap para próximo bônus, bônus atual |
     * | `monthly_commission` | Tier atual, projeção, potencial |
     * | `daily_pace` | Ritmo vs média diária |
     * | `my_shifts` | Turnos do dia com status |
     *
     * @queryParam store_id integer required ID da loja. Example: 1
     * @queryParam date string Data (YYYY-MM-DD), default: hoje. Example: 2026-01-07
     *
     * @response 200 scenario="Dashboard completo" {
     *   "data": {
     *     "date": "2026-01-07",
     *     "my_sales": { "count": 5, "total": 450.00 },
     *     "store_sales": { "count": 23, "total": 3200.00 },
     *     "bonus_gamification": {
     *       "current_amount": 450.00,
     *       "next_bonus_goal": 500.00,
     *       "gap_to_bonus": 50.00,
     *       "next_bonus_value": 10.00,
     *       "current_bonus_earned": 0,
     *       "message": "Faltam R$ 50,00 para ganhar R$ 10,00 de bônus!"
     *     },
     *     "monthly_commission": {
     *       "sales_mtd": 8500.00,
     *       "goal_amount": 15000.00,
     *       "achievement_rate": 56.67,
     *       "current_tier": 2.0,
     *       "current_commission_value": 170.00,
     *       "next_tier": 3.0,
     *       "potential_commission": 450.00
     *     },
     *     "daily_pace": {
     *       "today_sales": 450.00,
     *       "average_daily_sales": 566.67,
     *       "today_vs_average": -116.67,
     *       "status": "BEHIND"
     *     },
     *     "my_shifts": []
     *   },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
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
        $date = Carbon::parse($request->input('date', Carbon::today()->format('Y-m-d')));
        $month = $date->format('Y-m');

        if (!$user->hasAccessToStore($storeId)) {
            return $this->forbidden('You do not have access to this store.');
        }

        // Vendas do vendedor no dia (PDV)
        $mySales = DB::table('pdv_venda_itens as vi')
            ->join('pdv_vendas as v', function ($join) {
                $join->on('v.store_pdv_id', '=', 'vi.store_pdv_id')
                    ->on('v.canal', '=', 'vi.canal')
                    ->on('v.id_operacao', '=', 'vi.id_operacao');
            })
            ->join('pdv_user_mappings as pum', function ($join) {
                $join->on('pum.store_pdv_id', '=', 'vi.store_pdv_id')
                    ->on('pum.pdv_user_id', '=', 'vi.vendedor_pdv_id');
            })
            ->where('v.store_id', $storeId)
            ->where('pum.user_id', $user->id)
            ->where('pum.active', true)
            ->whereDate('v.data_hora', $date)
            ->selectRaw('COUNT(DISTINCT v.id) as count, COALESCE(SUM(vi.total), 0) as total')
            ->first();

        // Vendas da loja no dia (PDV)
        $storeSales = DB::table('pdv_vendas as v')
            ->where('v.store_id', $storeId)
            ->whereDate('v.data_hora', $date)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(v.total), 0) as total')
            ->first();

        // Turnos do vendedor (PDV)
        $myShifts = DB::table('pdv_turnos as t')
            ->join('pdv_user_mappings as pum', function ($join) {
                $join->on('pum.store_pdv_id', '=', 't.store_pdv_id')
                    ->on('pum.pdv_user_id', '=', 't.operador_pdv_id');
            })
            ->where('t.store_id', $storeId)
            ->where('pum.user_id', $user->id)
            ->where('pum.active', true)
            ->whereDate('t.data_hora_inicio', $date)
            ->select([
                't.id_turno',
                't.sequencial',
                't.fechado',
                't.data_hora_inicio',
                't.data_hora_termino'
            ])
            ->get()
            ->map(fn($t) => [
                'id' => $t->id_turno,
                'status' => $t->fechado ? 'closed' : 'open',
                'start' => $t->data_hora_inicio,
                'end' => $t->data_hora_termino
            ]);

        // Gamificação de Bônus
        $bonusGamification = $this->gamificationService->getBonusGamification(
            $storeId,
            $user->id,
            $date
        );

        // Projeção de Comissão Mensal
        $monthlyCommission = $this->gamificationService->getMonthlyCommissionProjection(
            $storeId,
            $user->id,
            $month
        );

        // Pace Diário
        $dailyPace = $this->gamificationService->getDailyPace(
            $storeId,
            $user->id,
            $date
        );

        return $this->success([
            'date' => $date->format('Y-m-d'),
            'my_sales' => [
                'count' => (int) $mySales->count,
                'total' => (float) $mySales->total,
            ],
            'store_sales' => [
                'count' => (int) $storeSales->count,
                'total' => (float) $storeSales->total,
            ],
            'bonus_gamification' => $bonusGamification,
            'monthly_commission' => $monthlyCommission,
            'daily_pace' => $dailyPace,
            'my_shifts' => $myShifts,
        ]);
    }

    /**
     * Dashboard do Conferente
     *
     * Retorna métricas para conferência de caixa e auditoria,
     * incluindo fechamentos pendentes e ranking de vendedores.
     *
     * **Foco:** Integridade de caixa e aprovação de fechamentos.
     *
     * **Quem pode usar:** Conferentes, Gerentes e Admins.
     *
     * **Métricas retornadas:**
     * | Métrica | Descrição |
     * |---------|-----------|
     * | `pending_closings` | Fechamentos aguardando aprovação |
     * | `pending_count` | Quantidade de pendentes |
     * | `store_sales` | Total de vendas da loja no dia |
     * | `shifts_today` | Resumo por status (open/closed/pending) |
     * | `top_sellers` | Top 5 vendedores do dia |
     *
     * @queryParam store_id integer required ID da loja. Example: 1
     * @queryParam date string Data (YYYY-MM-DD), default: hoje. Example: 2026-01-07
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
     *   }
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

        $storeSales = DB::table('pdv_vendas as v')
            ->where('v.store_id', $storeId)
            ->whereDate('v.data_hora', $date)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(v.total), 0) as total')
            ->first();

        $shiftsToday = DB::table('pdv_turnos as t')
            ->where('t.store_id', $storeId)
            ->whereDate('t.data_hora_inicio', $date)
            ->selectRaw('fechado, COUNT(*) as count')
            ->groupBy('fechado')
            ->get()
            ->mapWithKeys(fn($row) => [
                ($row->fechado ? 'closed' : 'open') => $row->count
            ]);

        $topSellers = DB::table('pdv_venda_itens as vi')
            ->join('pdv_vendas as v', function ($join) {
                $join->on('v.store_pdv_id', '=', 'vi.store_pdv_id')
                    ->on('v.canal', '=', 'vi.canal')
                    ->on('v.id_operacao', '=', 'vi.id_operacao');
            })
            ->join('pdv_user_mappings as pum', function ($join) {
                $join->on('pum.store_pdv_id', '=', 'vi.store_pdv_id')
                    ->on('pum.pdv_user_id', '=', 'vi.vendedor_pdv_id');
            })
            ->join('users', 'users.id', '=', 'pum.user_id')
            ->where('v.store_id', $storeId)
            ->where('pum.active', true)
            ->whereDate('v.data_hora', $date)
            ->selectRaw('pum.user_id as seller_id, users.name, SUM(vi.total) as total')
            ->groupBy('pum.user_id', 'users.name')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn($s) => [
                'seller_id' => $s->seller_id,
                'name' => $s->name,
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
     * Retorna visão consolidada de todas as lojas administradas,
     * com métricas mensais agregadas.
     *
     * **Foco:** Visão estratégica e gerencial do negócio.
     *
     * **Quem pode usar:** Admins e Gerentes.
     *
     * **Métricas retornadas:**
     * | Métrica | Descrição |
     * |---------|-----------|
     * | `total_sales` | Soma de vendas de todas as lojas no mês |
     * | `sales_by_store` | Vendas detalhadas por loja |
     * | `closings_summary` | Contagem de fechamentos por status |
     * | `top_sellers` | Top 10 vendedores do mês |
     *
     * @queryParam month string Mês (YYYY-MM), default: mês atual. Example: 2026-01
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
     *   }
     * }
     */
    public function admin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'period' => ['sometimes', 'string', 'in:today,yesterday,last_7_days,last_30_days,this_month,last_month'],
            'store_id' => ['sometimes', 'string'],
        ]);

        $user = $request->user();
        $window = $this->resolveReportWindow($validated, 'America/Sao_Paulo');
        $requestedStoreId = $this->resolveStoreIdFilter($validated['store_id'] ?? null);

        // Super admin vê todas as lojas
        if ($user->isSuperAdmin()) {
            $userStoreIds = Store::where('active', true)->pluck('id')->toArray();
        } else {
            $userStoreIds = $user->storeUsers()
                ->whereIn('role', ['admin', 'gerente'])
                ->pluck('store_id')
                ->toArray();
        }

        if (empty($userStoreIds)) {
            return $this->forbidden('You do not have admin access to any store.');
        }

        if ($requestedStoreId !== null) {
            if (!in_array($requestedStoreId, $userStoreIds, true)) {
                return $this->forbidden('You do not have admin access to this store.');
            }
            $userStoreIds = [$requestedStoreId];
        }

        $fromUtc = Carbon::instance($window['from_utc']->toMutable());
        $toUtc = Carbon::instance($window['to_utc']->toMutable());
        $fromLocalDate = $window['from_local']->toDateString();
        $toLocalDate = $window['to_local']->toDateString();

        // Resolve store identity for rows that may arrive without store_id:
        // store_id -> erp_loja_uuid -> pdv_lojas.guid_loja -> unique pdv_store_mapping.
        $storeMapUnique = DB::table('pdv_store_mappings as psm')
            ->selectRaw('psm.pdv_store_id, MIN(psm.store_id) as store_id')
            ->where('psm.active', true)
            ->groupBy('psm.pdv_store_id')
            ->havingRaw('COUNT(DISTINCT psm.store_id) = 1');

        $resolvedStoreIdExpr = 'COALESCE(v.store_id, s_guid.id, s_pl_guid.id, s_pl_name.id, smu.store_id)';
        $resolvedStoreNameExpr = "COALESCE(s.name, s_guid.name, s_pl_guid.name, s_pl_name.name, s_map.name, pl.nome_padronizado, pl.nome_hiper, CONCAT('Loja PDV ', v.store_pdv_id))";

        $salesBase = DB::table('pdv_vendas as v')
            ->leftJoin('stores as s', 's.id', '=', 'v.store_id')
            ->leftJoin('stores as s_guid', function ($join) {
                $join->on(DB::raw('LOWER(s_guid.guid)'), '=', DB::raw('LOWER(v.erp_loja_uuid)'));
            })
            ->leftJoin('pdv_lojas as pl', 'pl.id_ponto_venda', '=', 'v.store_pdv_id')
            ->leftJoin('stores as s_pl_guid', function ($join) {
                $join->on(DB::raw('LOWER(s_pl_guid.guid)'), '=', DB::raw('LOWER(pl.guid_loja)'));
            })
            ->leftJoin('stores as s_pl_name', function ($join) {
                $join->on(
                    DB::raw('LOWER(s_pl_name.name)'),
                    '=',
                    DB::raw('LOWER(COALESCE(pl.nome_padronizado, pl.nome_hiper))')
                );
            })
            ->leftJoinSub($storeMapUnique, 'smu', function ($join): void {
                $join->on('smu.pdv_store_id', '=', 'v.store_pdv_id');
            })
            ->leftJoin('stores as s_map', 's_map.id', '=', 'smu.store_id')
            ->whereBetween('v.data_hora', [$fromUtc, $toUtc])
            ->whereIn(DB::raw($resolvedStoreIdExpr), $userStoreIds);

        $salesByStore = (clone $salesBase)
            ->selectRaw("$resolvedStoreIdExpr as store_id")
            ->selectRaw("MAX($resolvedStoreNameExpr) as store_name")
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(v.total), 0) as total')
            ->groupBy(DB::raw($resolvedStoreIdExpr))
            ->get()
            ->map(fn($s) => [
                'store_id' => (int) $s->store_id,
                'store_name' => (string) $s->store_name,
                'count' => (int) $s->count,
                'total' => (float) $s->total,
            ]);

        $totalSales = (clone $salesBase)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(v.total), 0) as total')
            ->first();

        $closingsSummary = CashClosing::whereHas('cashShift', function ($q) use ($userStoreIds, $fromLocalDate, $toLocalDate) {
            $q->whereIn('store_id', $userStoreIds)
                ->whereBetween('date', [$fromLocalDate, $toLocalDate]);
        })
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $topSellers = DB::table('pdv_venda_itens as vi')
            ->join('pdv_vendas as v', function ($join) {
                $join->on('v.store_pdv_id', '=', 'vi.store_pdv_id')
                    ->on('v.canal', '=', 'vi.canal')
                    ->on('v.id_operacao', '=', 'vi.id_operacao');
            })
            ->leftJoin('stores as s_guid', function ($join) {
                $join->on(DB::raw('LOWER(s_guid.guid)'), '=', DB::raw('LOWER(v.erp_loja_uuid)'));
            })
            ->leftJoin('pdv_lojas as pl', 'pl.id_ponto_venda', '=', 'v.store_pdv_id')
            ->leftJoin('stores as s_pl_guid', function ($join) {
                $join->on(DB::raw('LOWER(s_pl_guid.guid)'), '=', DB::raw('LOWER(pl.guid_loja)'));
            })
            ->leftJoin('stores as s_pl_name', function ($join) {
                $join->on(
                    DB::raw('LOWER(s_pl_name.name)'),
                    '=',
                    DB::raw('LOWER(COALESCE(pl.nome_padronizado, pl.nome_hiper))')
                );
            })
            ->leftJoinSub($storeMapUnique, 'smu', function ($join): void {
                $join->on('smu.pdv_store_id', '=', 'v.store_pdv_id');
            })
            ->leftJoin('pdv_user_mappings as pum', function ($join) {
                $join->on('pum.store_pdv_id', '=', 'vi.store_pdv_id')
                    ->on('pum.pdv_user_id', '=', 'vi.vendedor_pdv_id')
                    ->where('pum.active', true);
            })
            ->leftJoin('users as u_map', 'u_map.id', '=', 'pum.user_id')
            ->leftJoin('users as u_guid', function ($join) {
                $join->on(DB::raw('LOWER(u_guid.guid)'), '=', DB::raw('LOWER(vi.vendedor_guid)'));
            })
            ->whereIn(DB::raw($resolvedStoreIdExpr), $userStoreIds)
            ->whereBetween('v.data_hora', [$fromUtc, $toUtc])
            ->where(function ($q) {
                $q->whereNotNull('u_guid.id')
                    ->orWhereNotNull('u_map.id');
            })
            ->selectRaw('COALESCE(u_guid.id, u_map.id) as seller_id')
            ->selectRaw('MAX(COALESCE(u_guid.name, u_map.name, vi.vendedor_nome)) as name')
            ->selectRaw('SUM(vi.total) as total, COUNT(DISTINCT v.id) as count')
            ->groupBy(DB::raw('COALESCE(u_guid.id, u_map.id)'))
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn($s) => [
                'seller_id' => (int) $s->seller_id,
                'name' => (string) $s->name,
                'total' => (float) $s->total,
                'count' => (int) $s->count,
            ]);

        return $this->success([
            'month' => $window['month'],
            'period' => $window['period_label'],
            'mode' => $window['mode'],
            'total_sales' => [
                'count' => (int) $totalSales->count,
                'total' => (float) $totalSales->total,
            ],
            'sales_by_store' => $salesByStore,
            'closings_summary' => $closingsSummary,
            'top_sellers' => $topSellers,
            'filters' => [
                'store_id' => $requestedStoreId,
                'month' => $window['month'],
                'period' => $window['period_label'],
                'mode' => $window['mode'],
                'from' => $window['from_utc']->toIso8601String(),
                'to' => $window['to_utc']->toIso8601String(),
                'timezone' => $window['timezone'],
            ],
        ]);
    }
}
