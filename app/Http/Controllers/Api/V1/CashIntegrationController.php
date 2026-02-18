<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Pdv\PdvClosureUnifiedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Fechamento de Caixa
 *
 * Endpoints para integração de dados reais do PDV.
 */
class CashIntegrationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PdvClosureUnifiedService $closureService
    ) {
    }

    /**
     * Obter dados de fechamento do PDV (visão unificada)
     *
     * Retorna os totais e detalhamento de pagamentos registrados no PDV
     * para um determinado Turno/Loja/Data, unificando dados dos canais
     * HIPER_CAIXA e HIPER_LOJA.
     *
     * @queryParam store_id integer required ID da loja. Example: 1
     * @queryParam date string required Data do turno (YYYY-MM-DD). Example: 2026-01-07
     * @queryParam shift_code string required Código do turno (M, T, N). Example: M
     */
    public function getClosureData(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'date' => ['required', 'date'],
            'shift_code' => ['required', 'string', 'in:M,T,N,1,2,3'],
        ]);

        $storeId = (int) $request->input('store_id');
        $date = $request->input('date');
        $shiftCode = $request->input('shift_code');

        // 1. Map Shift Code to PDV Periods
        $periods = $this->mapShiftCodeToPeriod($shiftCode);

        // 2. Buscar fechamentos unificados pelo service canônico
        $closures = $this->closureService->listUnifiedByStoreIdDatePeriod(
            $storeId,
            $date,
            $periods
        );

        if ($closures->isEmpty()) {
            return $this->success([
                'system_total' => 0.00,
                'system_total_caixa' => 0.00,
                'system_total_loja' => 0.00,
                'declared_total' => 0.00,
                'declared_consistent' => true,
                'canais_presentes' => [],
                'payments_sistema' => [],
                'payments_declarado' => [],
                'closures_found' => 0,
                'details' => [],
            ]);
        }

        // 3. Agregar totais de todos os fechamentos no período
        $systemTotal = $closures->sum('totais.sistema_unificado');
        $systemCaixa = $closures->sum('totais.sistema_caixa');
        $systemLoja = $closures->sum('totais.sistema_loja');
        $declaredTotal = $closures->sum('totais.declarado');
        $declaredConsistent = $closures->every('totais.declared_consistent');

        // Agregar pagamentos sistema (somar por id_finalizador entre closures)
        $paymentsSistema = $closures
            ->flatMap(fn($c) => $c['pagamentos']['sistema'])
            ->groupBy('id_finalizador')
            ->map(function ($group) {
                $first = $group->first();
                return [
                    'id_finalizador' => $first['id_finalizador'],
                    'label' => $first['meio_pagamento'],
                    'value' => (float) $group->sum('total'),
                    'qtd_vendas' => (int) $group->sum('qtd_vendas'),
                ];
            })
            ->values();

        // Agregar pagamentos declarado (somar por id_finalizador entre closures)
        $paymentsDeclarado = $closures
            ->flatMap(fn($c) => $c['pagamentos']['declarado'])
            ->groupBy('id_finalizador')
            ->map(function ($group) {
                $first = $group->first();
                return [
                    'id_finalizador' => $first['id_finalizador'],
                    'label' => $first['meio_pagamento'],
                    'value' => (float) $group->sum('total'),
                ];
            })
            ->values();

        // Todos os canais presentes
        $canais = $closures
            ->flatMap(fn($c) => $c['canais_presentes'])
            ->unique()
            ->values();

        return $this->success([
            'system_total' => (float) $systemTotal,
            'system_total_caixa' => (float) $systemCaixa,
            'system_total_loja' => (float) $systemLoja,
            'declared_total' => (float) $declaredTotal,
            'declared_consistent' => $declaredConsistent,
            'has_loja_sales' => $systemLoja > 0,
            'canais_presentes' => $canais,
            'payments_sistema' => $paymentsSistema,
            'payments_declarado' => $paymentsDeclarado,
            // Backwards-compatible fields
            'payments' => $paymentsSistema->map(fn($p) => [
                'label' => $p['label'],
                'value' => $p['value'],
            ]),
            'closures_found' => $closures->count(),
            'details' => $closures->map(fn($c) => [
                'closure_uuid' => $c['closure_uuid'],
                'sequencial' => $c['sequencial'],
                'periodo' => $c['periodo'],
                'sistema_caixa' => $c['totais']['sistema_caixa'],
                'sistema_loja' => $c['totais']['sistema_loja'],
                'sistema_unificado' => $c['totais']['sistema_unificado'],
                'declarado' => $c['totais']['declarado'],
                'falta' => $c['totais']['falta'],
                'sobra' => $c['totais']['sobra'],
                'operador' => $c['operador_nome'],
                'canais' => $c['canais_presentes'],
            ])->values(),
        ]);
    }

    /**
     * Maps ERP shift code to PDV periods
     */
    private function mapShiftCodeToPeriod(string $code): array
    {
        return match (strtoupper($code)) {
            'M', '1' => ['MATUTINO', '1', 'Turno 1'],
            'T', '2' => ['VESPERTINO', '2', 'Turno 2'],
            'N', '3' => ['NOTURNO', '3', 'Turno 3'],
            default => [$code] // Fallback attempt
        };
    }
}
