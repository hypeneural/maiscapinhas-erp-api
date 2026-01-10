<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Models\CashClosing;
use App\Models\CashClosingLine;
use App\Models\CashShift;
use App\Models\Sale;
use App\Models\StoreMonthlyGoal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service para relatórios de performance da loja.
 *
 * Fornece métricas gerenciais como:
 * - Atingimento de meta
 * - Crescimento YoY
 * - Projeções de fechamento
 */
class StorePerformanceService
{
    /**
     * Gera relatório de performance da loja.
     */
    public function getPerformance(int $storeId, string $month): array
    {
        $startOfMonth = Carbon::parse($month . '-01')->startOfMonth();
        $endOfMonth = Carbon::parse($month . '-01')->endOfMonth();
        $today = Carbon::today();

        // Dias corridos (até hoje ou fim do mês se for mês passado)
        $effectiveEndDate = $today->lt($endOfMonth) ? $today : $endOfMonth;
        $daysElapsed = $startOfMonth->diffInDays($effectiveEndDate) + 1;
        $daysTotal = $endOfMonth->day;

        // Vendas do mês atual
        $currentSales = (float) Sale::where('store_id', $storeId)
            ->whereBetween('sold_at', [$startOfMonth, $effectiveEndDate->endOfDay()])
            ->sum('amount');

        // Meta da loja
        $goal = StoreMonthlyGoal::forStore($storeId)->forMonth($month)->first();
        $goalAmount = $goal ? (float) $goal->goal_amount : 0;

        // Atingimento
        $achievementRate = $goalAmount > 0
            ? round(($currentSales / $goalAmount) * 100, 2)
            : 0;
        $remainingToGoal = max(0, $goalAmount - $currentSales);

        // ========== Comparação YoY ==========
        $lastYearMonth = Carbon::parse($month . '-01')->subYear()->format('Y-m');
        $lastYearStart = Carbon::parse($lastYearMonth . '-01')->startOfMonth();
        $lastYearEnd = Carbon::parse($lastYearMonth . '-01')->endOfMonth();

        // Mesmo período do ano passado (até o mesmo dia)
        $lastYearSameDay = min($daysElapsed, $lastYearEnd->day);
        $lastYearSamePeriodEnd = $lastYearStart->copy()->addDays($lastYearSameDay - 1);

        $samePeriodLastYear = (float) Sale::where('store_id', $storeId)
            ->whereBetween('sold_at', [$lastYearStart, $lastYearSamePeriodEnd->endOfDay()])
            ->sum('amount');

        $totalLastYear = (float) Sale::where('store_id', $storeId)
            ->whereBetween('sold_at', [$lastYearStart, $lastYearEnd->endOfDay()])
            ->sum('amount');

        // Crescimento YoY
        $yoyGrowth = $samePeriodLastYear > 0
            ? round((($currentSales / $samePeriodLastYear) - 1) * 100, 2)
            : ($currentSales > 0 ? 100 : 0);

        // ========== Comparação MoM (Mês anterior) ==========
        $lastMonthDate = Carbon::parse($month . '-01')->subMonth();
        $lastMonthStart = $lastMonthDate->copy()->startOfMonth();
        $lastMonthEnd = $lastMonthDate->copy()->endOfMonth();

        // Mesmo período do mês passado (até o mesmo dia)
        $lastMonthSameDay = min($daysElapsed, $lastMonthEnd->day);
        $lastMonthSamePeriodEnd = $lastMonthStart->copy()->addDays($lastMonthSameDay - 1);

        $samePeriodLastMonth = (float) Sale::where('store_id', $storeId)
            ->whereBetween('sold_at', [$lastMonthStart, $lastMonthSamePeriodEnd->endOfDay()])
            ->sum('amount');

        $totalLastMonth = (float) Sale::where('store_id', $storeId)
            ->whereBetween('sold_at', [$lastMonthStart, $lastMonthEnd->endOfDay()])
            ->sum('amount');

        // Crescimento MoM
        $momGrowth = $samePeriodLastMonth > 0
            ? round((($currentSales / $samePeriodLastMonth) - 1) * 100, 2)
            : ($currentSales > 0 ? 100 : 0);


        // ========== Projeções ==========
        // 1. Projeção Linear (Run Rate)
        $linearProjection = $daysElapsed > 0
            ? round(($currentSales / $daysElapsed) * $daysTotal, 2)
            : 0;

        // 2. Projeção por Tendência (baseada no YoY)
        $trendMultiplier = 1 + ($yoyGrowth / 100);
        $trendProjection = round($totalLastYear * $trendMultiplier, 2);

        // Status da projeção vs meta
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
            'period' => $month,
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
     * Gera relatório consolidado de múltiplas lojas.
     */
    public function getMultiStorePerformance(array $storeIds, string $month): array
    {
        $results = [];

        foreach ($storeIds as $storeId) {
            $results[] = $this->getPerformance($storeId, $month);
        }

        // Totais consolidados
        $totalCurrentSales = array_sum(array_column(array_column($results, 'sales'), 'current_amount'));
        $totalGoal = array_sum(array_column(array_column($results, 'sales'), 'goal_amount'));
        $totalLinearProjection = array_sum(array_column(array_column($results, 'forecast'), 'linear_projection'));

        return [
            'period' => $month,
            'stores' => $results,
            'consolidated' => [
                'total_sales' => $totalCurrentSales,
                'total_goal' => $totalGoal,
                'total_achievement_rate' => $totalGoal > 0 ? round(($totalCurrentSales / $totalGoal) * 100, 2) : 0,
                'total_linear_projection' => $totalLinearProjection,
            ],
        ];
    }
}
