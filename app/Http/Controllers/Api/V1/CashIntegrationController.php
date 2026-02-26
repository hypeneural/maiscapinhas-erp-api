<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\PdvTurno;
use App\Models\Store;
use App\Models\User;
use App\Services\Pdv\PdvClosureUnifiedService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'shift_code' => ['required', 'string', 'regex:/^\d+$/'],
        ]);

        $storeId = (int) $request->input('store_id');
        $date = $request->input('date');
        $shiftCode = $request->input('shift_code');

        // 1. Conferencia usa turno sequencial (1/2/3), nao periodo textual.
        $normalizedShiftCode = $this->normalizeShiftCode((string) $shiftCode);

        // 2. Buscar fechamentos unificados pelo service canônico
        $closures = $this->closureService->listUnifiedByStoreIdDateShiftCode(
            $storeId,
            $date,
            $normalizedShiftCode
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

        // Agregar pagamentos sistema usando entries_expected (valor correto por meio)
        // IMPORTANTE: NÃO usar pagamentos.sistema (soma bruta CAIXA+LOJA) — ele inclui
        // orçamentos/pedidos de HIPER_LOJA que inflam os valores.
        // Usar pagamentos.por_meio que já tem entries_expected = declarado + falta - sobra.
        $paymentsSistema = $closures
            ->flatMap(fn($c) => $c['pagamentos']['por_meio'])
            ->groupBy('id_finalizador')
            ->map(function ($group) {
                $first = $group->first();
                return [
                    'id_finalizador' => $first['id_finalizador'],
                    'label' => $first['meio_pagamento'],
                    'value' => (float) $group->sum('entries_expected'),
                    'qtd_vendas' => 0,
                ];
            })
            ->values();

        // Soma bruta (CAIXA+LOJA) mantida para referência/debugging
        $paymentsRawSistema = $closures
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
            'payments_sistema_raw' => $paymentsRawSistema,
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
     * List pending conference turns (closed/unified PDV turns without conference status).
     *
     * @queryParam store_id integer ID da loja (optional)
     * @queryParam date string Data YYYY-MM-DD (optional)
     * @queryParam shift_code string Codigo do turno (optional)
     * @queryParam limit integer Limite de linhas retornadas (1-300). Example: 100
     */
    public function getConferencePendingTurns(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'date' => ['nullable', 'date'],
            'shift_code' => ['nullable', 'string', 'regex:/^\d+$/'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:300'],
        ]);

        $user = $request->user();
        $storeId = $request->input('store_id');
        $date = $request->input('date');
        $shiftCode = $request->filled('shift_code')
            ? $this->normalizeShiftCode($request->input('shift_code'))
            : null;
        $limit = (int) $request->input('limit', 100);

        $allowedStoreIds = $this->resolveAllowedStoreIds($user);

        if ($storeId && !$user->isSuperAdmin() && !in_array((int) $storeId, $allowedStoreIds, true)) {
            return $this->forbidden('You do not have access to this store.');
        }

        $basePendingQuery = $this->pendingConferenceTurnosQuery($allowedStoreIds)
            ->join('stores as s', 's.id', '=', 'pending.store_id');

        if ($storeId) {
            $basePendingQuery->where('pending.store_id', (int) $storeId);
        }

        if ($date) {
            $basePendingQuery->where('pending.date_key', $date);
        }

        if ($shiftCode) {
            $basePendingQuery->where('pending.shift_code_raw', (string) ((int) $shiftCode));
        }

        $totalPending = (clone $basePendingQuery)->count('pending.closure_uuid');

        $turns = (clone $basePendingQuery)
            ->select([
                'pending.closure_uuid',
                'pending.store_id',
                's.name as store_name',
                's.guid as store_guid',
                'pending.date_key',
                'pending.shift_code_raw',
                'pending.reference_datetime',
                'pending.started_at',
                'pending.ended_at',
                'pending.responsavel_nome',
                'pending.responsavel_guid',
                'pending.operador_nome',
                'pending.total_value',
            ])
            ->orderByDesc('pending.date_key')
            ->orderByDesc('pending.reference_datetime')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                return [
                    'closure_uuid' => $row->closure_uuid,
                    'store' => [
                        'id' => (int) $row->store_id,
                        'name' => $row->store_name,
                        'guid' => $row->store_guid,
                    ],
                    'date' => $row->date_key,
                    'shift_code' => $this->normalizeShiftCode((string) $row->shift_code_raw),
                    'reference_datetime' => $row->reference_datetime,
                    'started_at' => $row->started_at,
                    'ended_at' => $row->ended_at,
                    'responsavel' => [
                        'nome' => $row->responsavel_nome,
                        'guid' => $row->responsavel_guid,
                    ],
                    'operador_nome' => $row->operador_nome,
                    'total_value' => (float) $row->total_value,
                ];
            })
            ->values();

        return $this->success([
            'total_pending' => $totalPending,
            'limit' => $limit,
            'turns' => $turns,
        ]);
    }

    /**
     * Get cascading filters for closure validation (Stores -> Dates -> Shifts -> Sellers)
     *
     * @queryParam store_id integer ID of the store (optional)
     * @queryParam date string Date YYYY-MM-DD (optional)
     * @queryParam shift_code string Shift code (optional)
     */
    public function getClosureFilters(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'date' => ['nullable', 'date'],
            'shift_code' => ['nullable', 'string', 'regex:/^\d+$/'],
        ]);

        $user = $request->user();
        $storeId = $request->input('store_id');
        $date = $request->input('date');
        $shiftCode = $request->filled('shift_code')
            ? $this->normalizeShiftCode($request->input('shift_code'))
            : null;

        $allowedStoreIds = $this->resolveAllowedStoreIds($user);

        if ($storeId && !$user->isSuperAdmin() && !in_array((int) $storeId, $allowedStoreIds, true)) {
            return $this->forbidden('You do not have access to this store.');
        }

        // A conferencia de lancamento deve listar apenas turnos pendentes (sem status de closing).
        $pendingBaseQuery = $this->pendingConferenceTurnosQuery($allowedStoreIds);

        // 1. Stores
        $storeIds = (clone $pendingBaseQuery)
            ->select('pending.store_id')
            ->distinct()
            ->pluck('pending.store_id');

        $stores = Store::query()
            ->whereIn('id', $storeIds)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        // 2. Dates (store-scoped)
        $dates = collect();
        if ($storeId) {
            $dates = (clone $pendingBaseQuery)
                ->where('pending.store_id', (int) $storeId)
                ->select('pending.date_key')
                ->distinct()
                ->orderBy('pending.date_key', 'asc')
                ->pluck('date_key')
                ->values();
        }

        // 3. Shifts (store + date scoped)
        $shifts = collect();
        if ($storeId && $date) {
            $shifts = (clone $pendingBaseQuery)
                ->where('pending.store_id', (int) $storeId)
                ->where('pending.date_key', $date)
                ->pluck('pending.shift_code_raw')
                ->map(fn($code) => $this->normalizeShiftCode((string) $code))
                ->filter()
                ->unique()
                ->sort()
                ->values();
        }

        // 4. Sellers (lista completa para permitir vincular qualquer responsavel)
        $allSellers = User::query()
            ->whereNotNull('guid')
            ->where('active', true)
            ->select('id', 'name', 'guid')
            ->orderBy('name')
            ->get();

        $suggestedSeller = null;

        if ($storeId && $date && $shiftCode) {
            // Find canonical PDV responsible for this closed shift.
            $turno = (clone $pendingBaseQuery)
                ->where('pending.store_id', (int) $storeId)
                ->where('pending.date_key', $date)
                ->where('pending.shift_code_raw', (string) ((int) $shiftCode))
                ->orderByDesc('pending.reference_datetime')
                ->first();

            if ($turno && $turno->responsavel_guid) {
                $responsibleGuid = strtolower(trim((string) $turno->responsavel_guid));
                $responsible = $allSellers->first(function ($seller) use ($responsibleGuid) {
                    return strtolower(trim((string) $seller->guid)) === $responsibleGuid;
                });
                if ($responsible) {
                    $suggestedSeller = [
                        'id' => $responsible->id,
                        'name' => $responsible->name,
                        'guid' => $responsible->guid,
                    ];
                }
            }
        }

        return $this->success([
            'stores' => $stores,
            'dates' => $dates,
            'shifts' => $shifts,
            'sellers' => [
                'suggested' => $suggestedSeller,
                'all' => $allSellers,
            ],
        ]);
    }

    /**
     * Resolve stores available for the current user.
     *
     * For users without store_users bindings, fallback to active stores
     * so conference screens keep working for global roles.
     *
     * @return array<int, int>
     */
    private function resolveAllowedStoreIds($user): array
    {
        $allowedStoreIds = $user->isSuperAdmin()
            ? Store::query()->where('active', true)->pluck('id')->toArray()
            : $user->storeUsers()->pluck('store_id')->toArray();

        if (!empty($allowedStoreIds)) {
            return array_values(array_unique(array_map('intval', $allowedStoreIds)));
        }

        return Store::query()
            ->where('active', true)
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->toArray();
    }

    /**
     * Base query for pending conference turns:
     * - Fechamento unificado com todos os canais fechados (status FECHADO)
     * - Sem qualquer cash_closing associado para a combinacao loja/data/turno
     */
    private function pendingConferenceTurnosQuery(array $allowedStoreIds): Builder
    {
        $closedUnifiedTurns = PdvTurno::query()
            ->from('pdv_turnos as pt')
            ->leftJoin('pdv_closures as pc', 'pc.closure_uuid', '=', 'pt.closure_uuid')
            ->whereIn('pt.store_id', $allowedStoreIds)
            ->whereNotNull('pt.store_id')
            ->whereNotNull('pt.sequencial')
            ->whereNotNull('pt.closure_uuid')
            ->groupBy(
                'pt.closure_uuid',
                'pt.store_id',
                DB::raw('CAST(pt.sequencial AS CHAR)')
            )
            ->havingRaw('MIN(CASE WHEN pt.fechado = 1 THEN 1 ELSE 0 END) = 1')
            ->havingRaw('MAX(CASE WHEN pt.data_hora_fechamento IS NOT NULL THEN 1 ELSE 0 END) = 1')
            ->selectRaw('
                pt.closure_uuid,
                pt.store_id,
                DATE(CONVERT_TZ(MIN(pt.data_hora_inicio), \'+00:00\', \'-03:00\')) as date_key,
                CAST(pt.sequencial AS CHAR) as shift_code_raw,
                MAX(COALESCE(pt.data_hora_fechamento, pt.data_hora_termino, pt.data_hora_inicio)) as reference_datetime,
                MIN(pt.data_hora_inicio) as started_at,
                MAX(pt.data_hora_termino) as ended_at,
                MAX(pt.responsavel_nome) as responsavel_nome,
                MAX(pt.responsavel_guid) as responsavel_guid,
                MAX(pt.operador_nome) as operador_nome,
                COALESCE(MAX(pc.total_sistema_unificado), MAX(pt.total_declarado), 0) as total_value
            ');

        return DB::query()
            ->fromSub($closedUnifiedTurns, 'pending')
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('cash_shifts as cs')
                    ->join('cash_closings as cc', 'cc.cash_shift_id', '=', 'cs.id')
                    ->whereColumn('cs.store_id', 'pending.store_id')
                    ->whereRaw('cs.date = pending.date_key')
                    ->where(function ($shiftMatch) {
                        $shiftMatch
                            ->whereRaw('CAST(cs.shift_code AS CHAR) = pending.shift_code_raw')
                            ->orWhere(function ($alias) {
                                $alias->whereRaw("pending.shift_code_raw = '1'")
                                    ->whereRaw("UPPER(cs.shift_code) = 'M'");
                            })
                            ->orWhere(function ($alias) {
                                $alias->whereRaw("pending.shift_code_raw = '2'")
                                    ->whereRaw("UPPER(cs.shift_code) = 'T'");
                            })
                            ->orWhere(function ($alias) {
                                $alias->whereRaw("pending.shift_code_raw = '3'")
                                    ->whereRaw("UPPER(cs.shift_code) = 'N'");
                            });
                    });
            });
    }

    /**
     * Normalize shift code to canonical numeric string.
     */
    private function normalizeShiftCode(string $code): string
    {
        return match (strtoupper(trim($code))) {
            'M', '1' => '1',
            'T', '2' => '2',
            'N', '3' => '3',
            default => trim($code),
        };
    }

}
