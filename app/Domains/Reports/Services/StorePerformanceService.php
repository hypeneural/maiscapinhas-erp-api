<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Models\StoreMonthlyGoal;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Service para relatorios de performance da loja.
 */
class StorePerformanceService
{
    /**
     * Gera relatorio de performance da loja.
     */
    public function getPerformance(
        int $storeId,
        string $month,
        ?string $storeName = null,
        ?CarbonImmutable $fromUtc = null,
        ?CarbonImmutable $toUtc = null,
        ?string $periodLabel = null
    ): array
    {
        $isCustomWindow = $fromUtc !== null && $toUtc !== null;

        if ($isCustomWindow) {
            $startOfPeriod = Carbon::instance($fromUtc->toMutable());
            $endOfPeriod = Carbon::instance($toUtc->toMutable());
            $effectiveEndDate = $endOfPeriod;
            $daysElapsed = $startOfPeriod->copy()->startOfDay()->diffInDays($effectiveEndDate->copy()->startOfDay()) + 1;
            $daysTotal = $daysElapsed;
            $periodOutput = $periodLabel ?? ($startOfPeriod->toDateString() . ' to ' . $endOfPeriod->toDateString());
        } else {
            $startOfPeriod = Carbon::parse($month . '-01')->startOfMonth();
            $endOfPeriod = Carbon::parse($month . '-01')->endOfMonth();
            $today = Carbon::today();

            // Dias corridos (ate hoje ou fim do mes se for mes passado)
            $effectiveEndDate = $today->lt($endOfPeriod) ? $today : $endOfPeriod;
            $daysElapsed = $startOfPeriod->diffInDays($effectiveEndDate) + 1;
            $daysTotal = $endOfPeriod->day;
            $periodOutput = $month;
        }

        // Fetch store name if not provided
        if ($storeName === null) {
            $storeName = \App\Models\Store::find($storeId)?->name;
        }

        // Vendas do mes atual (PDV Data Source)
        $currentSales = $this->sumSalesForStore(
            $storeId,
            $startOfPeriod,
            $effectiveEndDate->copy()->endOfDay()
        );

        // Meta da loja
        $goal = StoreMonthlyGoal::forStore($storeId)->forMonth($month)->first();
        $goalAmount = $goal ? (float) $goal->goal_amount : 0;

        // Atingimento
        $achievementRate = $goalAmount > 0
            ? round(($currentSales / $goalAmount) * 100, 2)
            : 0;
        $remainingToGoal = max(0, $goalAmount - $currentSales);

        // ========== Comparacao YoY ==========
        if ($isCustomWindow) {
            $lastYearStart = $startOfPeriod->copy()->subYear();
            $lastYearSamePeriodEnd = $effectiveEndDate->copy()->subYear();
            $lastYearEnd = $lastYearSamePeriodEnd;
        } else {
            $lastYearMonth = Carbon::parse($month . '-01')->subYear()->format('Y-m');
            $lastYearStart = Carbon::parse($lastYearMonth . '-01')->startOfMonth();
            $lastYearEnd = Carbon::parse($lastYearMonth . '-01')->endOfMonth();

            // Mesmo periodo do ano passado (ate o mesmo dia)
            $lastYearSameDay = min($daysElapsed, $lastYearEnd->day);
            $lastYearSamePeriodEnd = $lastYearStart->copy()->addDays($lastYearSameDay - 1);
        }

        $samePeriodLastYear = $this->sumSalesForStore(
            $storeId,
            $lastYearStart,
            $lastYearSamePeriodEnd->copy()->endOfDay()
        );

        $totalLastYear = $this->sumSalesForStore(
            $storeId,
            $lastYearStart,
            $lastYearEnd->copy()->endOfDay()
        );

        // Crescimento YoY
        $yoyGrowth = $samePeriodLastYear > 0
            ? round((($currentSales / $samePeriodLastYear) - 1) * 100, 2)
            : ($currentSales > 0 ? 100 : 0);

        // ========== Comparacao MoM (Mes anterior) ==========
        if ($isCustomWindow) {
            $lastMonthStart = $startOfPeriod->copy()->subDays($daysElapsed);
            $lastMonthSamePeriodEnd = $effectiveEndDate->copy()->subDays($daysElapsed);
            $lastMonthEnd = $lastMonthSamePeriodEnd;
        } else {
            $lastMonthDate = Carbon::parse($month . '-01')->subMonth();
            $lastMonthStart = $lastMonthDate->copy()->startOfMonth();
            $lastMonthEnd = $lastMonthDate->copy()->endOfMonth();

            // Mesmo periodo do mes passado (ate o mesmo dia)
            $lastMonthSameDay = min($daysElapsed, $lastMonthEnd->day);
            $lastMonthSamePeriodEnd = $lastMonthStart->copy()->addDays($lastMonthSameDay - 1);
        }

        $samePeriodLastMonth = $this->sumSalesForStore(
            $storeId,
            $lastMonthStart,
            $lastMonthSamePeriodEnd->copy()->endOfDay()
        );

        $totalLastMonth = $this->sumSalesForStore(
            $storeId,
            $lastMonthStart,
            $lastMonthEnd->copy()->endOfDay()
        );

        // Crescimento MoM
        $momGrowth = $samePeriodLastMonth > 0
            ? round((($currentSales / $samePeriodLastMonth) - 1) * 100, 2)
            : ($currentSales > 0 ? 100 : 0);

        // ========== Projecoes ==========
        // 1. Projecao linear (run rate)
        $linearProjection = $daysElapsed > 0
            ? round(($currentSales / $daysElapsed) * $daysTotal, 2)
            : 0;

        // 2. Projecao por tendencia (baseada no YoY)
        $trendMultiplier = 1 + ($yoyGrowth / 100);
        $trendProjection = round($totalLastYear * $trendMultiplier, 2);

        // Status da projecao vs meta
        $projectionStatus = 'ON_TRACK';
        if ($goalAmount > 0) {
            $bestProjection = max($linearProjection, $trendProjection);
            $ratio = $bestProjection / $goalAmount;
            if ($ratio >= 1.0) {
                $projectionStatus = 'ON_TRACK';
            } elseif ($ratio >= 0.9) {
                $projectionStatus = 'AT_RISK';
            } else {
                $projectionStatus = 'BEHIND';
            }
        }

        return [
            'store_id' => $storeId,
            'store_name' => $storeName,
            'period' => $periodOutput,
            'month' => $month,
            'days_elapsed' => $daysElapsed,
            'days_total' => $daysTotal,

            'sales' => [
                'current_amount' => $currentSales,
                'goal_amount' => $goalAmount,
                'achievement_rate' => $achievementRate,
                'remaining_to_goal' => $remainingToGoal,
            ],

            'comparison' => [
                'same_period_last_year' => $samePeriodLastYear,
                'total_last_year_month' => $totalLastYear,
                'yoy_growth' => $yoyGrowth,
                'same_period_last_month' => $samePeriodLastMonth,
                'total_last_month' => $totalLastMonth,
                'mom_growth' => $momGrowth,
            ],

            'forecast' => [
                'linear_projection' => $linearProjection,
                'trend_projection' => $trendProjection,
                'status' => $projectionStatus,
            ],
        ];
    }

    /**
     * Gera relatorio consolidado de multiplas lojas.
     */
    public function getMultiStorePerformance(
        array $storeIds,
        string $month,
        ?CarbonImmutable $fromUtc = null,
        ?CarbonImmutable $toUtc = null,
        ?string $periodLabel = null
    ): array
    {
        $results = [];

        // Pre-fetch store names to avoid N+1 queries
        $storeNames = \App\Models\Store::whereIn('id', $storeIds)
            ->pluck('name', 'id');

        foreach ($storeIds as $storeId) {
            $storeName = $storeNames[$storeId] ?? null;
            $results[] = $this->getPerformance($storeId, $month, $storeName, $fromUtc, $toUtc, $periodLabel);
        }

        // Totais consolidados
        $totalCurrentSales = array_sum(array_column(array_column($results, 'sales'), 'current_amount'));
        $totalGoal = array_sum(array_column(array_column($results, 'sales'), 'goal_amount'));
        $totalLinearProjection = array_sum(array_column(array_column($results, 'forecast'), 'linear_projection'));

        return [
            'period' => $periodLabel ?? $month,
            'month' => $month,
            'stores' => $results,
            'consolidated' => [
                'total_sales' => $totalCurrentSales,
                'total_goal' => $totalGoal,
                'total_achievement_rate' => $totalGoal > 0 ? round(($totalCurrentSales / $totalGoal) * 100, 2) : 0,
                'total_linear_projection' => $totalLinearProjection,
            ],
        ];
    }

    private function sumSalesForStore(int $storeId, Carbon $from, Carbon $to): float
    {
        $storeMapUnique = DB::table('pdv_store_mappings as psm')
            ->selectRaw('psm.pdv_store_id, MIN(psm.store_id) as store_id')
            ->where('psm.active', true)
            ->groupBy('psm.pdv_store_id')
            ->havingRaw('COUNT(DISTINCT psm.store_id) = 1');

        $resolvedStoreIdExpr = 'COALESCE(v.store_id, s_guid.id, s_pl_guid.id, s_pl_name.id, smu.store_id)';

        return (float) DB::table('pdv_vendas as v')
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
            ->whereBetween('v.data_hora', [$from, $to])
            ->whereRaw("$resolvedStoreIdExpr = ?", [$storeId])
            ->sum('v.total');
    }
}
