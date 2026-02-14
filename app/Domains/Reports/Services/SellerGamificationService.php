<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Models\Sale;
use App\Models\StoreMonthlyGoal;
use App\Services\RulesService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service para cálculos de gamificação do vendedor.
 * 
 * Fornece métricas motivacionais como:
 * - Gap para próximo bônus
 * - Projeção de comissão mensal
 * - Velocímetro diário (pace)
 */
class SellerGamificationService
{
    public function __construct(
        private RulesService $rulesService
    ) {
    }

    /**
     * Calcula gamificação de bônus diário.
     * 
     * Mostra quanto falta para atingir o próximo nível de bônus.
     */
    public function getBonusGamification(int $storeId, int $userId, Carbon $date): array
    {
        // Vendas do dia (PDV Data Source)
        $todaySales = (float) DB::table('pdv_venda_itens as vi')
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
            ->where('pum.user_id', $userId)
            ->where('pum.active', true)
            ->whereDate('v.data_hora', $date)
            ->sum('vi.total');

        // Buscar regra de bônus aplicável
        $rule = $this->rulesService->getApplicableBonusRule($storeId, $date);

        if (!$rule) {
            return [
                'current_amount' => $todaySales,
                'next_bonus_goal' => null,
                'gap_to_bonus' => null,
                'next_bonus_value' => null,
                'current_bonus_earned' => 0,
                'message' => 'Nenhuma regra de bônus configurada.',
            ];
        }

        // Parse config_json para encontrar faixas
        $tiers = is_array($rule->config_json) ? $rule->config_json : json_decode($rule->config_json, true);

        // Ordenar por min_sales
        usort($tiers, fn($a, $b) => $a['min_sales'] <=> $b['min_sales']);

        $currentBonus = 0;
        $nextGoal = null;
        $nextBonusValue = null;
        $message = '';

        foreach ($tiers as $index => $tier) {
            $minSales = (float) $tier['min_sales'];
            $bonusValue = (float) $tier['bonus'];

            if ($todaySales >= $minSales) {
                $currentBonus = $bonusValue;

                // Verificar se há próximo nível
                if (isset($tiers[$index + 1])) {
                    $nextGoal = (float) $tiers[$index + 1]['min_sales'];
                    $nextBonusValue = (float) $tiers[$index + 1]['bonus'];
                }
            } else {
                // Ainda não atingiu este nível
                if ($nextGoal === null) {
                    $nextGoal = $minSales;
                    $nextBonusValue = $bonusValue;
                }
                break;
            }
        }

        $gap = $nextGoal !== null ? max(0, $nextGoal - $todaySales) : 0;

        // Gerar mensagem motivacional
        if ($nextGoal && $gap > 0) {
            $message = sprintf(
                'Você vendeu R$ %.2f. Faltam R$ %.2f para ganhar R$ %.2f de bônus!',
                $todaySales,
                $gap,
                $nextBonusValue
            );
        } elseif ($currentBonus > 0 && $nextGoal === null) {
            $message = sprintf(
                'Parabéns! Você já garantiu R$ %.2f de bônus no nível máximo!',
                $currentBonus
            );
        } elseif ($currentBonus > 0) {
            $message = sprintf(
                'Bônus atual: R$ %.2f. Busque mais R$ %.2f para subir de nível!',
                $currentBonus,
                $gap
            );
        } else {
            $message = sprintf(
                'Venda R$ %.2f para começar a ganhar bônus!',
                $nextGoal ?? 0
            );
        }

        return [
            'current_amount' => $todaySales,
            'next_bonus_goal' => $nextGoal,
            'gap_to_bonus' => $gap,
            'next_bonus_value' => $nextBonusValue,
            'current_bonus_earned' => $currentBonus,
            'message' => $message,
        ];
    }

    /**
     * Calcula projeção de comissão mensal.
     * 
     * Mostra tier atual, próximo tier e valores projetados.
     */
    public function getMonthlyCommissionProjection(int $storeId, int $userId, string $month): array
    {
        $startDate = Carbon::parse($month . '-01')->startOfMonth();
        $endDate = Carbon::parse($month . '-01')->endOfMonth();
        $today = Carbon::today();
        $daysElapsed = min($today->day, $endDate->day);
        $daysTotal = $endDate->day;

        // Vendas do mês até agora
        // Vendas do mês até agora (PDV Data Source)
        $salesMtd = (float) DB::table('pdv_venda_itens as vi')
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
            ->where('pum.user_id', $userId)
            ->where('pum.active', true)
            ->whereBetween('v.data_hora', [$startDate, min($today, $endDate)->endOfDay()])
            ->sum('vi.total');

        // Meta individual do vendedor
        $goal = StoreMonthlyGoal::forStore($storeId)->forMonth($month)->first();
        $individualGoal = $goal ? $goal->getIndividualGoal($userId) : 0;

        // Calcular atingimento
        $achievementRate = $individualGoal > 0
            ? round(($salesMtd / $individualGoal) * 100, 2)
            : 0;

        // Buscar regra de comissão
        $rule = $this->rulesService->getApplicableCommissionRule($storeId, $month);

        $currentTier = 0;
        $nextTier = null;
        $nextTierGoal = null;
        $nextTierGoalPercent = null;

        if ($rule) {
            $tiers = is_array($rule->config_json) ? $rule->config_json : json_decode($rule->config_json, true);
            usort($tiers, fn($a, $b) => $a['min_attainment'] <=> $b['min_attainment']);

            foreach ($tiers as $index => $tier) {
                $minAttainment = (float) $tier['min_attainment'];
                $rate = (float) $tier['rate'];

                if ($achievementRate >= $minAttainment) {
                    $currentTier = $rate;

                    if (isset($tiers[$index + 1])) {
                        $nextTier = (float) $tiers[$index + 1]['rate'];
                        $nextTierGoalPercent = (float) $tiers[$index + 1]['min_attainment'];
                        $nextTierGoal = $individualGoal * ($nextTierGoalPercent / 100);
                    }
                } else {
                    if ($nextTier === null) {
                        $nextTier = $rate;
                        $nextTierGoalPercent = $minAttainment;
                        $nextTierGoal = $individualGoal * ($minAttainment / 100);
                    }
                    break;
                }
            }
        }

        // Valor atual da comissão
        $currentCommissionValue = round($salesMtd * ($currentTier / 100), 2);

        // Projeção de vendas no mês (run rate)
        $projectedSales = $daysElapsed > 0
            ? round(($salesMtd / $daysElapsed) * $daysTotal, 2)
            : 0;

        // Projeção de atingimento
        $projectedAchievement = $individualGoal > 0
            ? round(($projectedSales / $individualGoal) * 100, 2)
            : 0;

        // Calcular tier projetado
        $projectedTier = $currentTier;
        if ($rule) {
            $tiers = is_array($rule->config_json) ? $rule->config_json : json_decode($rule->config_json, true);
            usort($tiers, fn($a, $b) => $a['min_attainment'] <=> $b['min_attainment']);

            foreach ($tiers as $tier) {
                if ($projectedAchievement >= (float) $tier['min_attainment']) {
                    $projectedTier = (float) $tier['rate'];
                }
            }
        }

        // Comissão potencial no final do mês
        $potentialCommission = round($projectedSales * ($projectedTier / 100), 2);

        // Gap para próximo tier
        $gapToNextTier = $nextTierGoal !== null ? max(0, $nextTierGoal - $salesMtd) : 0;

        return [
            'month' => $month,
            'sales_mtd' => $salesMtd,
            'goal_amount' => $individualGoal,
            'achievement_rate' => $achievementRate,
            'days_elapsed' => $daysElapsed,
            'days_total' => $daysTotal,

            'current_tier' => $currentTier,
            'current_commission_value' => $currentCommissionValue,

            'next_tier' => $nextTier,
            'next_tier_goal' => $nextTierGoal,
            'next_tier_goal_percent' => $nextTierGoalPercent,
            'gap_to_next_tier' => $gapToNextTier,

            'projected_sales' => $projectedSales,
            'projected_achievement' => $projectedAchievement,
            'projected_tier' => $projectedTier,
            'potential_commission' => $potentialCommission,
        ];
    }

    /**
     * Calcula o pace (ritmo) diário do vendedor.
     */
    public function getDailyPace(int $storeId, int $userId, Carbon $date): array
    {
        $month = $date->format('Y-m');
        $startOfMonth = Carbon::parse($month . '-01')->startOfMonth();

        // Dias trabalhados no mês (até ontem, para ter média confiável)
        // Dias trabalhados no mês (até ontem, para ter média confiável)
        $daysWorked = DB::table('pdv_venda_itens as vi')
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
            ->where('pum.user_id', $userId)
            ->where('pum.active', true)
            ->whereBetween('v.data_hora', [$startOfMonth, $date->copy()->subDay()->endOfDay()])
            ->distinct()
            ->count(DB::raw('DATE(v.data_hora)'));

        // Casting direto pois count retorna int
        $daysWorked = (int) $daysWorked;

        // Vendas até ontem
        // Vendas até ontem
        $salesUntilYesterday = (float) DB::table('pdv_venda_itens as vi')
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
            ->where('pum.user_id', $userId)
            ->where('pum.active', true)
            ->whereBetween('v.data_hora', [$startOfMonth, $date->copy()->subDay()->endOfDay()])
            ->sum('vi.total');

        // Média diária
        $averageDailySales = $daysWorked > 0
            ? round($salesUntilYesterday / $daysWorked, 2)
            : 0;

        // Vendas de hoje (PDV Data Source)
        $todaySales = (float) DB::table('pdv_venda_itens as vi')
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
            ->where('pum.user_id', $userId)
            ->where('pum.active', true)
            ->whereDate('v.data_hora', $date)
            ->sum('vi.total');

        // Comparação
        $todayVsAverage = round($todaySales - $averageDailySales, 2);

        // Status
        $status = 'ON_TRACK';
        if ($averageDailySales > 0) {
            $percentage = ($todaySales / $averageDailySales) * 100;
            if ($percentage >= 110) {
                $status = 'AHEAD';
            } elseif ($percentage < 80) {
                $status = 'BEHIND';
            }
        }

        return [
            'today_sales' => $todaySales,
            'average_daily_sales' => $averageDailySales,
            'today_vs_average' => $todayVsAverage,
            'days_worked_this_month' => $daysWorked,
            'status' => $status,
        ];
    }
}
