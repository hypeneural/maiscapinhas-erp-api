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
                'falta_total' => 0.00,
                'sobra_total' => 0.00,
                'entries_expected' => 0.00,
                'declared_consistent' => true,
                'canais_presentes' => [],
                'payments_sistema' => [],
                'payments_declarado' => [],
                'payments_falta' => [],
                'payments_sobra' => [],
                'closures_found' => 0,
                'details' => [],
            ]);
        }

        // 3. Agregar totais de todos os fechamentos no período
        $systemCaixa = $closures->sum('totais.sistema_caixa');
        $systemLoja = $closures->sum('totais.loja_total_sistema_raw');
        $entriesExpected = $closures->sum('totais.entries_expected');
        $declaredTotal = $closures->sum('totais.declarado');
        $faltaTotal = $closures->sum('totais.falta');
        $sobraTotal = $closures->sum('totais.sobra');
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

        // Agregar pagamentos falta (somar por id_finalizador entre closures)
        $paymentsFalta = $closures
            ->flatMap(fn($c) => $c['pagamentos']['falta'])
            ->groupBy('id_finalizador')
            ->map(function ($group) {
                $first = $group->first();
                return [
                    'id_finalizador' => $first['id_finalizador'],
                    'label' => $first['meio_pagamento'],
                    'value' => (float) $group->sum('total'),
                ];
            })
            ->values()
            ->filter(fn($p) => $p['value'] > 0)
            ->values();

        // Agregar pagamentos sobra (somar por id_finalizador entre closures)
        $paymentsSobra = $closures
            ->flatMap(fn($c) => $c['pagamentos']['sobra'])
            ->groupBy('id_finalizador')
            ->map(function ($group) {
                $first = $group->first();
                return [
                    'id_finalizador' => $first['id_finalizador'],
                    'label' => $first['meio_pagamento'],
                    'value' => (float) $group->sum('total'),
                ];
            })
            ->values()
            ->filter(fn($p) => $p['value'] > 0)
            ->values();

        // Todos os canais presentes
        $canais = $closures
            ->flatMap(fn($c) => $c['canais_presentes'])
            ->unique()
            ->values();

        return $this->success([
            'system_total' => (float) $entriesExpected,
            'system_total_caixa' => (float) $systemCaixa,
            'system_total_loja' => (float) $systemLoja,
            'declared_total' => (float) $declaredTotal,
            'falta_total' => (float) $faltaTotal,
            'sobra_total' => (float) $sobraTotal,
            'entries_expected' => (float) $entriesExpected,
            'declared_consistent' => $declaredConsistent,
            'has_loja_sales' => $systemLoja > 0,
            'canais_presentes' => $canais,
            'payments_sistema' => $paymentsSistema,
            'payments_declarado' => $paymentsDeclarado,
            'payments_falta' => $paymentsFalta,
            'payments_sobra' => $paymentsSobra,
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
                'sistema_loja' => $c['totais']['loja_total_sistema_raw'],
                'entries_expected' => $c['totais']['entries_expected'],
                'declarado' => $c['totais']['declarado'],
                'falta' => $c['totais']['falta'],
                'sobra' => $c['totais']['sobra'],
                'operador' => $c['operador_nome'],
                'responsavel' => [
                    'nome' => $c['responsavel_nome'] ?? null,
                    'guid' => $c['responsavel_guid'] ?? null,
                    'login' => $c['responsavel_login'] ?? null,
                ],
                'canais' => $c['canais_presentes'],
                'data_hora_inicio' => $c['data_hora_inicio'],
                'data_hora_termino' => $c['data_hora_termino'],
            ])->values(),
        ]);
    }

    /**
     * Diagnóstico completo de um fechamento (closure_uuid)
     *
     * Retorna TODOS os dados brutos e processados para validação
     * manual contra o ERP Gestão Online.
     *
     * @queryParam closure_uuid string  UUID do fechamento.
     * @queryParam store_pdv_id integer ID PDV da loja.
     * @queryParam date string          Data (YYYY-MM-DD).
     * @queryParam sequencial integer   Nro do turno (1, 2...).
     */
    public function diagnoseClosureData(Request $request): JsonResponse
    {
        // Accept either closure_uuid OR store_pdv_id + date + sequencial
        $closureUuid = $request->input('closure_uuid');

        if (!$closureUuid) {
            $request->validate([
                'store_pdv_id' => ['required', 'integer'],
                'date' => ['required', 'date'],
                'sequencial' => ['required', 'integer'],
            ]);

            $storePdvId = $request->input('store_pdv_id');
            $storeGuid = $request->input('store_guid');

            if (!$storePdvId && $storeGuid) {
                $storeRec = \DB::table('stores')->where('guid', $storeGuid)->first(['store_pdv_id']);
                if ($storeRec) {
                    $storePdvId = $storeRec->store_pdv_id;
                }
            }

            $storePdvId = (int) $storePdvId;
            $date = $request->input('date');
            $sequencial = (int) $request->input('sequencial');

            // Find the closure_uuid from turnos
            $turno = \DB::table('pdv_turnos')
                ->where('store_pdv_id', $storePdvId)
                ->whereDate('data_hora_inicio', $date)
                ->where('sequencial', $sequencial)
                ->whereNotNull('closure_uuid')
                ->first(['closure_uuid']);

            if (!$turno) {
                // Try to find turnos without closure_uuid
                $openTurnos = \DB::table('pdv_turnos')
                    ->where('store_pdv_id', $storePdvId)
                    ->whereDate('data_hora_inicio', $date)
                    ->where('sequencial', $sequencial)
                    ->get(['id', 'canal', 'id_turno', 'closure_uuid', 'fechado', 'total_sistema', 'total_declarado']);

                return $this->success([
                    'error' => 'closure_uuid not found for these parameters',
                    'turnos_encontrados' => $openTurnos,
                    'hint' => 'Os turnos existem mas closure_uuid está null. O turno_closure webhook pode não ter chegado ainda.',
                ]);
            }

            $closureUuid = $turno->closure_uuid;
        }

        // 1. Raw turnos data
        $turnos = \DB::table('pdv_turnos')
            ->where('closure_uuid', $closureUuid)
            ->get([
                'id',
                'canal',
                'id_turno',
                'closure_uuid',
                'store_pdv_id',
                'store_id',
                'sequencial',
                'periodo',
                'fechado',
                'operador_nome',
                'operador_guid',
                'responsavel_nome',
                'responsavel_guid',
                'total_sistema',
                'total_declarado',
                'total_falta',
                'total_sobra',
                'data_hora_inicio',
                'data_hora_termino',
                'data_hora_fechamento',
                'last_sync_id',
                'created_at',
                'updated_at',
            ]);

        // 2. Raw pagamentos data (grouped by turno/canal)
        $pagamentos = [];
        foreach ($turnos as $t) {
            $pags = \DB::table('pdv_turno_pagamentos')
                ->where('id_turno', $t->id_turno)
                ->where('canal', $t->canal)
                ->where('store_pdv_id', $t->store_pdv_id)
                ->get(['tipo', 'id_finalizador', 'meio_pagamento', 'total', 'qtd_vendas', 'closure_uuid']);

            $pagamentos[$t->canal] = $pags;
        }

        // 3. Unified service output (the canonical view)
        $unified = $this->closureService->getUnifiedByClosureUuid($closureUuid);

        // 4. Persisted pdv_closures record
        $pdvClosure = \DB::table('pdv_closures')
            ->where('closure_uuid', $closureUuid)
            ->first();

        // 5. Persisted pdv_closure_pagamentos
        $closurePagamentos = \DB::table('pdv_closure_pagamentos')
            ->where('closure_uuid', $closureUuid)
            ->get();

        // 6. Store info
        $storeInfo = null;
        if ($turnos->isNotEmpty()) {
            $storeId = $turnos->first()->store_id;
            if ($storeId) {
                $storeInfo = \DB::table('stores')
                    ->where('id', $storeId)
                    ->first(['id', 'name', 'guid']);
            }
        }

        return $this->success([
            'closure_uuid' => $closureUuid,
            'store' => $storeInfo,

            // Raw DB data
            'turnos_raw' => $turnos,
            'pagamentos_raw' => $pagamentos,

            // Unified service output
            'unified' => $unified,

            // Persisted canonical records
            'pdv_closure' => $pdvClosure,
            'pdv_closure_pagamentos' => $closurePagamentos,

            // Quick summary for validation
            'validation_summary' => $unified ? [
                'entries_expected' => $unified['totais']['entries_expected'] ?? null,
                'sistema_caixa' => $unified['totais']['sistema_caixa'] ?? null,
                'loja_total_sistema_raw' => $unified['totais']['loja_total_sistema_raw'] ?? null,
                'loja_cash_contribution_inferred' => $unified['totais']['loja_cash_contribution_inferred'] ?? null,
                'declarado' => $unified['totais']['declarado'] ?? null,
                'falta' => $unified['totais']['falta'] ?? null,
                'sobra' => $unified['totais']['sobra'] ?? null,
                'has_loja_sales' => $unified['totais']['has_loja_sales'] ?? null,
                'declared_consistent' => $unified['totais']['declared_consistent'] ?? null,
                'por_meio' => $unified['pagamentos']['por_meio'] ?? [],
            ] : null,
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
