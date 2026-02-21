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

    /**
     * Gera relatório GLOBAL de integridade agregando todas as lojas.
     *
     * Usa 2 queries SQL com GROUP BY store_id (não N+1).
     *
     * @param int[] $storeIds IDs das lojas do usuário
     * @param string $month Mês no formato YYYY-MM
     */
    public function getGlobalIntegrityReport(array $storeIds, string $month): array
    {
        $startTime = microtime(true);

        $startOfMonth = Carbon::parse($month . '-01')->startOfMonth()->format('Y-m-d');
        $endOfMonth = Carbon::parse($month . '-01')->endOfMonth()->format('Y-m-d');

        // ──────────────────────────────────────────────────────
        // Query 1: Agregação de linhas por store (integridade + divergências)
        // ──────────────────────────────────────────────────────
        $integrityRows = DB::table('cash_closing_lines as ccl')
            ->join('cash_closings as cc', 'cc.id', '=', 'ccl.cash_closing_id')
            ->join('cash_shifts as cs', 'cs.id', '=', 'cc.cash_shift_id')
            ->join('stores as s', 's.id', '=', 'cs.store_id')
            ->whereIn('cs.store_id', $storeIds)
            ->where('cc.status', 'approved')
            ->whereBetween('cs.date', [$startOfMonth, $endOfMonth])
            ->groupBy('cs.store_id', 's.name')
            ->select([
                'cs.store_id',
                's.name as store_name',
                DB::raw('SUM(ccl.system_value) as total_system'),
                DB::raw('SUM(ccl.real_value) as total_real'),
                DB::raw('SUM(ccl.diff_value) as total_diff'),
                DB::raw('COUNT(CASE WHEN ABS(ccl.diff_value) > 0.01 THEN 1 END) as divergence_lines'),
                DB::raw("COUNT(CASE WHEN ABS(ccl.diff_value) > 0.01 AND (ccl.justification_text IS NOT NULL AND ccl.justification_text != '') THEN 1 END) as justified_count"),
                DB::raw("COUNT(CASE WHEN ABS(ccl.diff_value) > 0.01 AND (ccl.justification_text IS NULL OR ccl.justification_text = '') THEN 1 END) as unjustified_count"),
            ])
            ->get()
            ->keyBy('store_id');

        // ──────────────────────────────────────────────────────
        // Query 2: Workflow por store (PDV turnos totais + cash_closings status)
        // ──────────────────────────────────────────────────────
        // 2a: Total de turnos fechados no PDV por store
        $pdvShifts = DB::table('pdv_turnos')
            ->whereIn('store_id', $storeIds)
            ->where('fechado', 1)
            ->whereBetween('data_hora_inicio', [
                $startOfMonth . ' 00:00:00',
                $endOfMonth . ' 23:59:59',
            ])
            ->groupBy('store_id')
            ->select([
                'store_id',
                DB::raw('COUNT(*) as total_shifts'),
            ])
            ->get()
            ->keyBy('store_id');

        // 2b: Contagem de cash_closings por status por store
        $closingCounts = DB::table('cash_closings as cc')
            ->join('cash_shifts as cs', 'cs.id', '=', 'cc.cash_shift_id')
            ->whereIn('cs.store_id', $storeIds)
            ->whereBetween('cs.date', [$startOfMonth, $endOfMonth])
            ->groupBy('cs.store_id')
            ->select([
                'cs.store_id',
                DB::raw("COUNT(CASE WHEN cc.status = 'approved' THEN 1 END) as closed_count"),
                DB::raw("COUNT(CASE WHEN cc.status = 'submitted' THEN 1 END) as pending_count"),
            ])
            ->get()
            ->keyBy('store_id');

        // ──────────────────────────────────────────────────────
        // Montar by_store + aggregar globais
        // ──────────────────────────────────────────────────────
        $storeNames = DB::table('stores')
            ->whereIn('id', $storeIds)
            ->pluck('name', 'id');

        $globalSystem = 0;
        $globalReal = 0;
        $globalDivergence = 0;
        $globalDivergenceLines = 0;
        $globalJustified = 0;
        $globalUnjustified = 0;
        $globalTotalShifts = 0;
        $globalClosedCount = 0;
        $globalPending = 0;
        $byStore = [];
        $allAlerts = [];
        $storesWithData = 0;

        foreach ($storeIds as $storeId) {
            $integrity = $integrityRows->get($storeId);
            $shifts = $pdvShifts->get($storeId);
            $closings = $closingCounts->get($storeId);

            $system = $integrity ? (float) $integrity->total_system : 0;
            $real = $integrity ? (float) $integrity->total_real : 0;
            $diff = $integrity ? (float) $integrity->total_diff : 0;
            $divLines = $integrity ? (int) $integrity->divergence_lines : 0;
            $just = $integrity ? (int) $integrity->justified_count : 0;
            $unjust = $integrity ? (int) $integrity->unjustified_count : 0;

            $totalShifts = $shifts ? (int) $shifts->total_shifts : 0;
            $closedCount = $closings ? (int) $closings->closed_count : 0;
            $pendingCount = $closings ? (int) $closings->pending_count : 0;

            $breakPct = $system > 0
                ? round((abs($diff) / $system) * 100, 4)
                : 0;

            $status = 'GREEN';
            if ($breakPct > 5)
                $status = 'RED';
            elseif ($breakPct > 2)
                $status = 'YELLOW';

            $completionRate = $totalShifts > 0
                ? round(($closedCount / $totalShifts) * 100, 2)
                : 0;

            $storeName = $storeNames->get($storeId, "Loja #{$storeId}");

            if ($integrity || $shifts || $closings) {
                $storesWithData++;
            }

            $globalSystem += $system;
            $globalReal += $real;
            $globalDivergence += $diff;
            $globalDivergenceLines += $divLines;
            $globalJustified += $just;
            $globalUnjustified += $unjust;
            $globalTotalShifts += $totalShifts;
            $globalClosedCount += $closedCount;
            $globalPending += $pendingCount;

            $byStore[] = [
                'store_id' => $storeId,
                'store_name' => $storeName,
                'cash_break_percentage' => $breakPct,
                'status' => $status,
                'total_divergence' => round($diff, 2),
                'total_system_value' => round($system, 2),
                'total_real_value' => round($real, 2),
                'completion_rate' => $completionRate,
                'shifts_total' => $totalShifts,
                'shifts_closed' => $closedCount,
                'pending_approval' => $pendingCount,
                'divergence_lines' => $divLines,
                'justified_count' => $just,
                'unjustified_count' => $unjust,
            ];

            // Alerts per store
            $storeAlerts = $this->generateAlerts(
                $breakPct,
                $unjust,
                $pendingCount,
                $totalShifts - $closedCount - $pendingCount
            );
            foreach ($storeAlerts as $alert) {
                $alert['store_id'] = $storeId;
                $alert['store_name'] = $storeName;
                $allAlerts[] = $alert;
            }
        }

        // Sort by_store by cash_break_percentage descending (worst first)
        usort($byStore, fn($a, $b) => $b['cash_break_percentage'] <=> $a['cash_break_percentage']);

        // Global percentages
        $globalBreakPct = $globalSystem > 0
            ? round((abs($globalDivergence) / $globalSystem) * 100, 4)
            : 0;

        $globalJustifiedRate = $globalDivergenceLines > 0
            ? round(($globalJustified / $globalDivergenceLines) * 100, 2)
            : 100;

        $globalStatus = 'GREEN';
        if ($globalBreakPct > 5)
            $globalStatus = 'RED';
        elseif ($globalBreakPct > 2)
            $globalStatus = 'YELLOW';

        $globalCompletionRate = $globalTotalShifts > 0
            ? round(($globalClosedCount / $globalTotalShifts) * 100, 2)
            : 0;

        $queryMs = round((microtime(true) - $startTime) * 1000, 1);

        return [
            'period' => $month,

            'global' => [
                'total_system_value' => round($globalSystem, 2),
                'total_real_value' => round($globalReal, 2),
                'total_divergence' => round($globalDivergence, 2),
                'cash_break_percentage' => $globalBreakPct,
                'status' => $globalStatus,
                'total_shifts' => $globalTotalShifts,
                'closed_count' => $globalClosedCount,
                'pending_approval' => $globalPending,
                'completion_rate' => $globalCompletionRate,
                'total_divergences' => $globalDivergenceLines,
                'justified_count' => $globalJustified,
                'unjustified_count' => $globalUnjustified,
                'justified_rate' => $globalJustifiedRate,
            ],

            'by_store' => $byStore,
            'alerts' => $allAlerts,
            'store_count' => count($storeIds),

            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'query_ms' => $queryMs,
                'stores_count' => count($storeIds),
                'stores_with_data' => $storesWithData,
                'data_completeness' => count($storeIds) > 0
                    ? round($storesWithData / count($storeIds), 2)
                    : 0,
            ],
        ];
    }
}
