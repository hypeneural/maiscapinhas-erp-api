<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Models\CashClosing;
use App\Models\CashClosingLine;
use App\Models\CashShift;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service para relatórios de integridade de caixa.
 *
 * Fornece métricas de auditoria como:
 * - % de quebra de caixa
 * - Taxa de divergências justificadas
 * - Alertas de anomalias
 */
class CashIntegrityService
{
    /**
     * Gera relatório de integridade de caixa.
     */
    public function getIntegrityReport(int $storeId, string $month): array
    {
        $startOfMonth = Carbon::parse($month . '-01')->startOfMonth();
        $endOfMonth = Carbon::parse($month . '-01')->endOfMonth();

        // Buscar todos os fechamentos aprovados do período
        $closings = CashClosing::whereHas('cashShift', function ($q) use ($storeId, $startOfMonth, $endOfMonth) {
            $q->where('store_id', $storeId)
                ->whereBetween('date', [$startOfMonth->format('Y-m-d'), $endOfMonth->format('Y-m-d')]);
        })
            ->where('status', CashClosing::STATUS_APPROVED)
            ->with('lines')
            ->get();

        // Calcular totais
        $totalSystemValue = 0;
        $totalRealValue = 0;
        $totalDivergence = 0;
        $justifiedCount = 0;
        $unjustifiedCount = 0;
        $totalLinesWithDivergence = 0;

        foreach ($closings as $closing) {
            foreach ($closing->lines as $line) {
                $systemValue = (float) $line->system_value;
                $realValue = (float) $line->real_value;
                $diffValue = (float) $line->diff_value;

                $totalSystemValue += $systemValue;
                $totalRealValue += $realValue;
                $totalDivergence += $diffValue;

                // Contar divergências
                if (abs($diffValue) > 0.01) { // Considerar diferença > 1 centavo
                    $totalLinesWithDivergence++;

                    if (!empty($line->justification_text)) {
                        $justifiedCount++;
                    } else {
                        $unjustifiedCount++;
                    }
                }
            }
        }

        // % de Quebra de Caixa
        $cashBreakPercentage = $totalSystemValue > 0
            ? round((abs($totalDivergence) / $totalSystemValue) * 100, 4)
            : 0;

        // Taxa de justificação
        $justifiedRate = $totalLinesWithDivergence > 0
            ? round(($justifiedCount / $totalLinesWithDivergence) * 100, 2)
            : 100; // 100% se não há divergências

        // Determinar status
        $status = 'GREEN';
        if ($cashBreakPercentage > 5) {
            $status = 'RED';
        } elseif ($cashBreakPercentage > 2) {
            $status = 'YELLOW';
        }

        // Buscar fechamentos pendentes (backlog)
        $pendingCount = CashClosing::whereHas('cashShift', function ($q) use ($storeId) {
            $q->where('store_id', $storeId);
        })
            ->where('status', CashClosing::STATUS_SUBMITTED)
            ->count();

        // Total de turnos no período (Baseado na realidade do PDV e não apenas no que foi iniciado)
        $totalShifts = DB::table('pdv_turnos')
            ->where('store_id', $storeId)
            ->where('fechado', 1)
            ->whereBetween('data_hora_inicio', [
                $startOfMonth->format('Y-m-d 00:00:00'),
                $endOfMonth->format('Y-m-d 23:59:59')
            ])
            ->count();

        // Fechamentos concluídos
        $closedCount = $closings->count();

        return [
            'store_id' => $storeId,
            'period' => $month,

            'cash_integrity' => [
                'total_system_value' => round($totalSystemValue, 2),
                'total_real_value' => round($totalRealValue, 2),
                'total_divergence' => round($totalDivergence, 2),
                'cash_break_percentage' => $cashBreakPercentage,
                'status' => $status, // GREEN, YELLOW, RED
            ],

            'divergence_analysis' => [
                'total_lines_with_divergence' => $totalLinesWithDivergence,
                'justified_count' => $justifiedCount,
                'unjustified_count' => $unjustifiedCount,
                'justified_rate' => $justifiedRate,
            ],

            'workflow_status' => [
                'total_shifts' => $totalShifts,
                'closed_count' => $closedCount,
                'pending_approval' => $pendingCount,
                'completion_rate' => $totalShifts > 0
                    ? round(($closedCount / $totalShifts) * 100, 2)
                    : 0,
            ],

            'alerts' => $this->generateAlerts(
                $cashBreakPercentage,
                $unjustifiedCount,
                $pendingCount,
                $totalShifts - $closedCount - $pendingCount
            ),
        ];
    }

    /**
     * Gera alertas baseados nas métricas.
     */
    private function generateAlerts(
        float $cashBreakPercentage,
        int $unjustifiedCount,
        int $pendingCount,
        int $openCount
    ): array {
        $alerts = [];

        if ($cashBreakPercentage > 5) {
            $alerts[] = [
                'type' => 'CRITICAL',
                'code' => 'HIGH_CASH_BREAK',
                'message' => sprintf(
                    'Quebra de caixa de %.2f%% está acima do limite crítico (5%%).',
                    $cashBreakPercentage
                ),
            ];
        } elseif ($cashBreakPercentage > 2) {
            $alerts[] = [
                'type' => 'WARNING',
                'code' => 'ELEVATED_CASH_BREAK',
                'message' => sprintf(
                    'Quebra de caixa de %.2f%% está acima do limite aceitável (2%%).',
                    $cashBreakPercentage
                ),
            ];
        }

        if ($unjustifiedCount > 0) {
            $alerts[] = [
                'type' => 'WARNING',
                'code' => 'UNJUSTIFIED_DIVERGENCES',
                'message' => sprintf(
                    'Existem %d divergências não justificadas.',
                    $unjustifiedCount
                ),
            ];
        }

        if ($pendingCount > 5) {
            $alerts[] = [
                'type' => 'INFO',
                'code' => 'PENDING_BACKLOG',
                'message' => sprintf(
                    '%d fechamentos aguardando aprovação.',
                    $pendingCount
                ),
            ];
        }

        if ($openCount > 3) {
            $alerts[] = [
                'type' => 'WARNING',
                'code' => 'OPEN_SHIFTS',
                'message' => sprintf(
                    '%d turnos ainda não foram fechados.',
                    $openCount
                ),
            ];
        }

        return $alerts;
    }
}
