<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pdv\PdvReportsRankingVendedorLojaRequest;
use App\Http\Requests\Pdv\PdvReportsRankingVendedoresRequest;
use App\Http\Requests\Pdv\PdvReportsTurnosRequest;
use App\Http\Requests\Pdv\PdvReportsVendaDetalheRequest;
use App\Http\Requests\Pdv\PdvReportsVendasRequest;
use App\Http\Requests\Pdv\PdvReportsOperacoesRequest;
use App\Http\Traits\ApiResponse;
use App\Support\Pdv\PdvStoreResolver;
use App\Support\Audit\AuditContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @group PDV - Relatorios
 *
 * Endpoints de consulta e analytics do PDV Sync v3.
 *
 * Escopo:
 * - Vendas por periodo, vendedor, canal, turno e meio de pagamento
 * - Turnos com totais de sistema/declarado/falta
 * - Rankings de vendedores e vendedor x loja
 *
 * Autorizacao:
 * - Requer `auth:sanctum`
 * - Usuario comum: apenas lojas vinculadas em `store_users`
 * - Super admin: visao global
 */
class PdvReportsController extends Controller
{
    use ApiResponse;

    /**
     * Listar turnos PDV por data
     *
     * Retorna os turnos da data informada com consolidado de fechamento de caixa
     * e totais por tipo de pagamento (`sistema`, `declarado`, `falta`).
     *
     * @authenticated
     * @queryParam store_id integer ID da loja interna (`stores.id`). Obrigatorio se `store_pdv_id` nao for informado. Example: 1
     * @queryParam store_pdv_id integer ID da loja no PDV (`store.id_ponto_venda`). Obrigatorio se `store_id` nao for informado. Example: 13
     * @queryParam store_alias string Alias da loja PDV para desambiguar quando `store_pdv_id` colide. Example: Loja 8 - MC Mata Atlântica
     * @queryParam date string required Data de referencia no formato `YYYY-MM-DD`. Example: 2026-02-12
     * @queryParam sequencial integer Filtrar por numero sequencial do turno. Example: 2
     * @queryParam periodo string Filtrar por periodo do turno. Valores: `MATUTINO`, `VESPERTINO`, `NOTURNO`. Example: MATUTINO
     * @queryParam fechado boolean Filtrar status do turno (`true/false` ou `1/0`). Example: true
     * @queryParam operador_id integer Filtrar por operador do turno (`operador_pdv_id`). Example: 12
     * @queryParam responsavel_id integer Filtrar por responsavel do turno (`responsavel_pdv_id`). Example: 80
     *
     * @response 200 {
     *   "data": {
     *     "filters": {
     *       "store_id": 1,
     *       "store_pdv_id": 13,
     *       "date": "2026-02-12",
     *       "sequencial": null,
     *       "periodo": null,
     *       "fechado": null,
     *       "operador_id": null,
     *       "responsavel_id": null
     *     },
     *     "summary": {
     *       "qtd_turnos": 1,
     *       "qtd_turnos_fechados": 1,
     *       "qtd_turnos_falta": 1,
     *       "qtd_turnos_sobra": 0,
     *       "qtd_turnos_conferido": 0,
     *       "total_sistema": 1250.9,
     *       "total_declarado": 1240,
     *       "total_falta": 10.9,
     *       "total_falta_absoluto": 10.9
     *     },
     *     "turnos": []
     *   },
     *   "meta": {
     *     "request_id": "req-123",
     *     "timestamp": "2026-02-12T10:00:00+00:00"
     *   }
     * }
     * @response 403 {"message":"Voce nao tem acesso a esta loja."}
     * @response 422 {"message":"The given data was invalid.","errors":{"store":["Informe store_id ou store_pdv_id."]}}
     */
    public function turnos(PdvReportsTurnosRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $storeId = isset($validated['store_id']) ? (int) $validated['store_id'] : null;
        $storePdvId = isset($validated['store_pdv_id']) ? (int) $validated['store_pdv_id'] : null;
        $storeAlias = isset($validated['store_alias']) ? trim((string) $validated['store_alias']) : null;
        if ($storeId === null && $storePdvId === null) {
            throw ValidationException::withMessages([
                'store' => ['Informe store_id ou store_pdv_id.'],
            ]);
        }

        $scope = $this->resolveStoreScope($request, $storeId, $storePdvId, $storeAlias);
        $date = CarbonImmutable::parse((string) $validated['date'])->toDateString();

        $query = DB::table('pdv_turnos as t')
            ->select([
                't.store_id',
                't.store_pdv_id',
                't.id_turno',
                't.sequencial',
                't.fechado',
                't.data_hora_inicio',
                't.data_hora_termino',
                't.duracao_minutos',
                't.periodo',
                't.operador_pdv_id',
                't.operador_nome',
                't.responsavel_pdv_id',
                't.responsavel_nome',
                't.total_sistema',
                't.total_declarado',
                't.total_falta',
                't.qtd_vendas',
                't.total_vendas',
                't.qtd_vendedores',
                't.canal',
            ])
            ->whereDate('t.data_hora_inicio', $date);

        $this->applyStoreScopeToQuery($query, $scope, 't');

        if (isset($validated['sequencial'])) {
            $query->where('t.sequencial', (int) $validated['sequencial']);
        }
        if (!empty($validated['periodo'])) {
            $query->where('t.periodo', (string) $validated['periodo']);
        }
        if (array_key_exists('fechado', $validated)) {
            $query->where('t.fechado', (bool) $validated['fechado']);
        }
        if (isset($validated['operador_id'])) {
            $query->where('t.operador_pdv_id', (int) $validated['operador_id']);
        }
        if (isset($validated['responsavel_id'])) {
            $query->where('t.responsavel_pdv_id', (int) $validated['responsavel_id']);
        }
        if (!empty($validated['canal'])) {
            $query->where('t.canal', (string) $validated['canal']);
        }
        if (isset($validated['vendedor_id'])) {
            // Filtrar turnos que possuem vendas de um vendedor específico
            $query->whereExists(function ($sub) use ($validated) {
                $sub->select(DB::raw(1))
                    ->from('pdv_vendas as v')
                    ->whereColumn('v.store_pdv_id', 't.store_pdv_id')
                    ->whereColumn('v.id_turno', 't.id_turno')
                    ->where('v.vendedor_pdv_id', (int) $validated['vendedor_id']);
            });
        }

        $turnos = $query
            ->orderBy('t.sequencial')
            ->orderBy('t.data_hora_inicio')
            ->get();

        $pagamentosPorTurno = $this->loadTurnoPagamentos($turnos);
        $rows = $turnos->map(function ($turno) use ($pagamentosPorTurno): array {
            $key = $this->turnoCompositeKey((int) $turno->store_pdv_id, (string) $turno->id_turno);
            $pagamentos = $pagamentosPorTurno[$key] ?? [
                'sistema' => [],
                'declarado' => [],
                'falta' => [],
            ];
            $totalFalta = $turno->total_falta !== null ? (float) $turno->total_falta : null;
            $faltaCaixaTipo = match (true) {
                $totalFalta === null => null,
                $totalFalta > 0 => 'FALTA',
                $totalFalta < 0 => 'SOBRA',
                default => 'CONFERIDO',
            };

            return [
                'store_id' => $turno->store_id !== null ? (int) $turno->store_id : null,
                'store_pdv_id' => (int) $turno->store_pdv_id,
                'id_turno' => (string) $turno->id_turno,
                'canal' => $turno->canal ?? 'HIPER_CAIXA',
                'sequencial' => $turno->sequencial !== null ? (int) $turno->sequencial : null,
                'status' => (bool) $turno->fechado ? 'FECHADO' : 'ABERTO',
                'fechado' => (bool) $turno->fechado,
                'data_hora_inicio' => $this->toIso8601($turno->data_hora_inicio),
                'data_hora_termino' => $this->toIso8601($turno->data_hora_termino),
                'duracao_minutos' => $turno->duracao_minutos !== null ? (int) $turno->duracao_minutos : null,
                'periodo' => $turno->periodo,
                'operador' => [
                    'id_usuario' => $turno->operador_pdv_id !== null ? (int) $turno->operador_pdv_id : null,
                    'nome' => $turno->operador_nome,
                ],
                'responsavel' => [
                    'id_usuario' => $turno->responsavel_pdv_id !== null ? (int) $turno->responsavel_pdv_id : null,
                    'nome' => $turno->responsavel_nome,
                ],
                'totais' => [
                    'total_sistema' => (float) $turno->total_sistema,
                    'total_declarado' => $turno->total_declarado !== null ? (float) $turno->total_declarado : null,
                    'total_falta' => $totalFalta,
                    'falta_caixa_tipo' => $faltaCaixaTipo,
                    'falta_caixa_valor_absoluto' => $totalFalta !== null ? abs($totalFalta) : null,
                    'qtd_vendas' => $turno->qtd_vendas !== null ? (int) $turno->qtd_vendas : 0,
                    'total_vendas' => $turno->total_vendas !== null ? (float) $turno->total_vendas : 0.0,
                    'qtd_vendedores' => $turno->qtd_vendedores !== null ? (int) $turno->qtd_vendedores : 0,
                ],
                'pagamentos' => $pagamentos,
            ];
        })->values();

        return $this->success([
            'filters' => [
                'store_id' => $scope['store_id'],
                'store_pdv_id' => $scope['store_pdv_id'],
                'store_alias' => $scope['store_alias'],
                'date' => $date,
                'sequencial' => isset($validated['sequencial']) ? (int) $validated['sequencial'] : null,
                'periodo' => $validated['periodo'] ?? null,
                'fechado' => array_key_exists('fechado', $validated) ? (bool) $validated['fechado'] : null,
                'operador_id' => isset($validated['operador_id']) ? (int) $validated['operador_id'] : null,
                'responsavel_id' => isset($validated['responsavel_id']) ? (int) $validated['responsavel_id'] : null,
                'canal' => $validated['canal'] ?? null,
                'vendedor_id' => isset($validated['vendedor_id']) ? (int) $validated['vendedor_id'] : null,
            ],
            'summary' => [
                'qtd_turnos' => $rows->count(),
                'qtd_turnos_fechados' => $rows->where('fechado', true)->count(),
                'qtd_turnos_falta' => $rows->where('totais.falta_caixa_tipo', 'FALTA')->count(),
                'qtd_turnos_sobra' => $rows->where('totais.falta_caixa_tipo', 'SOBRA')->count(),
                'qtd_turnos_conferido' => $rows->where('totais.falta_caixa_tipo', 'CONFERIDO')->count(),
                'total_sistema' => round((float) $rows->sum('totais.total_sistema'), 2),
                'total_declarado' => round((float) $rows->sum('totais.total_declarado'), 2),
                'total_falta' => round((float) $rows->sum('totais.total_falta'), 2),
                'total_falta_absoluto' => round((float) $rows->sum(
                    static fn(array $row): float => abs((float) data_get($row, 'totais.total_falta', 0))
                ), 2),
            ],
            'turnos' => $rows->all(),
        ]);
    }

    /**
     * Relatorio Hierarquico de Turnos (V5)
     * 
     * Estrutura: Turno -> Vendedor -> Canal -> Pagamento
     *
     * @authenticated
     * @queryParam store_id integer ID da loja interna. Example: 1
     * @queryParam store_pdv_id integer ID da loja PDV. Example: 13
     * @queryParam date string required Data de referencia. Example: 2026-02-12
     */
    public function turnosHierarchical(PdvReportsTurnosRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $date = CarbonImmutable::parse((string) $validated['date'])->toDateString();

        // Reuse scope logic (validated in previous method, but we need to re-validate basic inputs)
        $storeId = isset($validated['store_id']) ? (int) $validated['store_id'] : null;
        $storePdvId = isset($validated['store_pdv_id']) ? (int) $validated['store_pdv_id'] : null;
        $storeAlias = isset($validated['store_alias']) ? trim((string) $validated['store_alias']) : null;
        if ($storeId === null && $storePdvId === null) {
            throw ValidationException::withMessages(['store' => ['Informe store_id ou store_pdv_id.']]);
        }
        $scope = $this->resolveStoreScope($request, $storeId, $storePdvId, $storeAlias);

        // 1. Fetch Turnos
        $turnosQuery = DB::table('pdv_turnos as t')
            ->select([
                't.store_pdv_id',
                't.id_turno',
                't.sequencial',
                't.fechado',
                't.periodo',
                't.data_hora_inicio',
                't.data_hora_termino'
            ])
            ->whereDate('t.data_hora_inicio', $date);
        $this->applyStoreScopeToQuery($turnosQuery, $scope, 't');
        $turnos = $turnosQuery->get()->keyBy(fn($t) => $t->store_pdv_id . '|' . $t->id_turno);

        if ($turnos->isEmpty()) {
            return $this->success(['date' => $date, 'data' => []]);
        }

        // 2. Fetch Data
        $storePdvIds = $turnos->pluck('store_pdv_id')->unique()->toArray();
        $turnoIds = $turnos->pluck('id_turno')->unique()->toArray();

        // Items: Who sold what (and how much)
        $items = DB::table('pdv_venda_itens as vi')
            ->join('pdv_vendas as v', function ($join) {
                $join->on('v.store_pdv_id', '=', 'vi.store_pdv_id')
                    ->on('v.canal', '=', 'vi.canal')
                    ->on('v.id_operacao', '=', 'vi.id_operacao');
            })
            ->whereIn('v.store_pdv_id', $storePdvIds)
            ->whereIn('v.id_turno', $turnoIds)
            ->select([
                'v.store_pdv_id',
                'v.id_turno',
                'v.canal',
                'v.id_operacao',
                'vi.vendedor_guid',
                'vi.vendedor_pdv_id',
                'vi.vendedor_nome',
                'vi.total as valor_item'
            ])
            ->get();

        // Payments: How it was paid
        $payments = DB::table('pdv_venda_pagamentos as vp')
            ->join('pdv_vendas as v', function ($join) {
                $join->on('v.store_pdv_id', '=', 'vp.store_pdv_id')
                    ->on('v.canal', '=', 'vp.canal')
                    ->on('v.id_operacao', '=', 'vp.id_operacao');
            })
            ->whereIn('v.store_pdv_id', $storePdvIds)
            ->whereIn('v.id_turno', $turnoIds)
            ->select([
                'v.store_pdv_id',
                'v.id_turno',
                'v.canal',
                'v.id_operacao',
                'vp.meio_pagamento',
                'vp.valor as valor_pagamento'
            ])
            ->get();

        // 3. Aggregation Strategy: Proportional Split
        $opsShares = [];
        foreach ($items as $item) {
            $opKey = $item->store_pdv_id . '|' . $item->canal . '|' . $item->id_operacao;
            if (!isset($opsShares[$opKey])) {
                $opsShares[$opKey] = ['total' => 0.0, 'sellers' => [], 'turno_id' => $item->id_turno];
            }

            $sellerKey = $item->vendedor_guid ?: ('legacy_' . $item->vendedor_pdv_id);
            if (!isset($opsShares[$opKey]['sellers'][$sellerKey])) {
                $opsShares[$opKey]['sellers'][$sellerKey] = [
                    'guid' => $item->vendedor_guid,
                    'id_pdv' => $item->vendedor_pdv_id,
                    'nome' => $item->vendedor_nome,
                    'total_items' => 0.0
                ];
            }

            $val = (float) $item->valor_item;
            $opsShares[$opKey]['total'] += $val;
            $opsShares[$opKey]['sellers'][$sellerKey]['total_items'] += $val;
        }

        $hierarchy = [];

        foreach ($payments as $pay) {
            $opKey = $pay->store_pdv_id . '|' . $pay->canal . '|' . $pay->id_operacao;
            $opData = $opsShares[$opKey] ?? null;

            if (!$opData)
                continue;

            $turnoKey = $pay->store_pdv_id . '|' . $pay->id_turno;
            if (!isset($turnos[$turnoKey]))
                continue;

            $turnoObj = $turnos[$turnoKey];
            $turnoId = (string) $pay->id_turno;

            $opTotal = $opData['total'];
            $paymentValue = (float) $pay->valor_pagamento;
            $meio = $pay->meio_pagamento ?? 'OUTROS';
            $canal = $pay->canal;

            foreach ($opData['sellers'] as $sellerKey => $seller) {
                $share = ($opTotal > 0) ? ($seller['total_items'] / $opTotal) : 0;
                if ($opTotal == 0 && count($opData['sellers']) > 0) {
                    $share = 1.0 / count($opData['sellers']);
                }

                $attribValue = $paymentValue * $share;

                if (!isset($hierarchy[$turnoId])) {
                    $hierarchy[$turnoId] = [
                        'sequencial' => $turnoObj->sequencial,
                        'fechado' => (bool) $turnoObj->fechado,
                        'id_turno' => $turnoId,
                        'periodo' => $turnoObj->periodo,
                        'data_hora_inicio' => $this->toIso8601($turnoObj->data_hora_inicio),
                        'vendedores' => []
                    ];
                }

                if (!isset($hierarchy[$turnoId]['vendedores'][$sellerKey])) {
                    $hierarchy[$turnoId]['vendedores'][$sellerKey] = [
                        'guid' => $seller['guid'],
                        'id_pdv' => $seller['id_pdv'],
                        'nome' => $seller['nome'] ?? 'Desconhecido',
                        'total_venda' => 0.0,
                        'canais' => []
                    ];
                }

                if (!isset($hierarchy[$turnoId]['vendedores'][$sellerKey]['canais'][$canal])) {
                    $hierarchy[$turnoId]['vendedores'][$sellerKey]['canais'][$canal] = [
                        'pagamentos' => [],
                        'total_canal' => 0.0
                    ];
                }

                if (!isset($hierarchy[$turnoId]['vendedores'][$sellerKey]['canais'][$canal]['pagamentos'][$meio])) {
                    $hierarchy[$turnoId]['vendedores'][$sellerKey]['canais'][$canal]['pagamentos'][$meio] = 0.0;
                }

                $hierarchy[$turnoId]['vendedores'][$sellerKey]['canais'][$canal]['pagamentos'][$meio] += $attribValue;
                $hierarchy[$turnoId]['vendedores'][$sellerKey]['canais'][$canal]['total_canal'] += $attribValue;
                $hierarchy[$turnoId]['vendedores'][$sellerKey]['total_venda'] += $attribValue;
            }
        }

        // Rounding values including top-level totals if needed
        $hierarchy = array_map(function ($turno) {
            usort($turno['vendedores'], fn($a, $b) => $b['total_venda'] <=> $a['total_venda']); // Sort sellers by value
            $turno['vendedores'] = array_values(array_map(function ($vendedor) {
                $vendedor['total_venda'] = round($vendedor['total_venda'], 2);
                $vendedor['canais'] = array_map(function ($canal) {
                    $canal['total_canal'] = round($canal['total_canal'], 2);
                    $canal['pagamentos'] = array_map(fn($val) => round($val, 2), $canal['pagamentos']);
                    return $canal;
                }, $vendedor['canais']);
                return $vendedor;
            }, $turno['vendedores']));
            return $turno;
        }, $hierarchy);


        return $this->success([
            'date' => $date,
            'store_id' => $scope['store_id'],
            'store_pdv_id' => $scope['store_pdv_id'],
            'data' => array_values($hierarchy)
        ]);
    }

    /**
     * Listar vendas PDV com filtros inteligentes
     *
     * Retorna vendas com agregados de itens e pagamentos por operacao.
     *
     * @authenticated
     * @queryParam store_id integer ID da loja interna (`stores.id`). Example: 1
     * @queryParam store_pdv_id integer ID da loja no PDV (`store.id_ponto_venda`). Example: 13
     * @queryParam store_alias string Alias da loja PDV para desambiguar quando `store_pdv_id` colide. Example: Loja 8 - MC Mata Atlântica
     * @queryParam from string Data inicial (`YYYY-MM-DD`). Default: hoje-30d. Example: 2026-02-01
     * @queryParam to string Data final (`YYYY-MM-DD`). Default: hoje. Example: 2026-02-12
     * @queryParam vendedor_id integer Filtrar por vendedor (`vendedor_pdv_id`). Example: 80
     * @queryParam canal string Filtrar por canal. Valores: `HIPER_CAIXA`, `HIPER_LOJA`. Example: HIPER_LOJA
     * @queryParam id_turno string Filtrar por ID de turno (UUID/string). Example: 656335C4-D6C4-455A-8E3D-FF6B3F570C64
     * @queryParam id_finalizador integer Filtrar por finalizador (ex.: Pix, credito). Example: 5
     * @queryParam meio_pagamento string Filtrar por nome do meio de pagamento. Example: Pix
     * @queryParam per_page integer Tamanho da pagina (1-100). Default: 25. Example: 25
     * @queryParam sort string Ordenacao por data. Valores: `asc`, `desc`. Default: `desc`. Example: desc
     *
     * @response 200 {
     *   "data": [
     *     {
     *       "store_id": 1,
     *       "store_pdv_id": 13,
     *       "id_operacao": 12345,
     *       "canal": "HIPER_CAIXA",
     *       "id_turno": "656335C4-D6C4-455A-8E3D-FF6B3F570C64",
     *       "data_hora": "2026-02-12T09:55:00+00:00",
     *       "total": 49.9,
     *       "itens": {"qtd_linhas": 1, "qtd_total": 1, "valor_total": 49.9},
     *       "pagamentos": {"qtd_linhas": 1, "valor_total": 50}
     *     }
     *   ],
     *   "summary": {"total_vendas": 1, "total_vendido": 49.9},
     *   "filters": {"canal": "HIPER_CAIXA"},
     *   "meta": {
     *     "request_id": "req-123",
     *     "timestamp": "2026-02-12T10:00:00+00:00",
     *     "pagination": {"total": 1, "per_page": 25, "current_page": 1, "last_page": 1}
     *   }
     * }
     * @response 403 {"message":"Voce nao tem acesso a esta loja."}
     * @response 422 {"message":"The given data was invalid.","errors":{"to":["O campo to deve ser maior ou igual ao campo from."]}}
     */
    public function vendas(PdvReportsVendasRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $storeId = isset($validated['store_id']) ? (int) $validated['store_id'] : null;
        $storePdvId = isset($validated['store_pdv_id']) ? (int) $validated['store_pdv_id'] : null;
        $storeAlias = isset($validated['store_alias']) ? trim((string) $validated['store_alias']) : null;
        $scope = $this->resolveStoreScope($request, $storeId, $storePdvId, $storeAlias);


        $timezone = 'America/Sao_Paulo';

        $from = isset($validated['from'])
            ? CarbonImmutable::parse((string) $validated['from'], $timezone)->startOfDay()->setTimezone('UTC')
            : CarbonImmutable::now($timezone)->subDays(30)->startOfDay()->setTimezone('UTC');
        $to = isset($validated['to'])
            ? CarbonImmutable::parse((string) $validated['to'], $timezone)->endOfDay()->setTimezone('UTC')
            : CarbonImmutable::now($timezone)->endOfDay()->setTimezone('UTC');

        if ($to->lt($from)) {
            throw ValidationException::withMessages([
                'to' => ['O campo to deve ser maior ou igual ao campo from.'],
            ]);
        }

        $itemAgg = DB::table('pdv_venda_itens as vi')
            ->select([
                'vi.store_pdv_id',
                'vi.canal',
                'vi.id_operacao',
                DB::raw('COUNT(*) as itens_count'),
                DB::raw('COALESCE(SUM(vi.qtd), 0) as itens_qtd_total'),
                DB::raw('COALESCE(SUM(vi.total), 0) as itens_valor_total'),
                DB::raw('MIN(vi.vendedor_pdv_id) as vendedor_pdv_id'),
                DB::raw('MAX(vi.vendedor_guid) as vendedor_guid'), // Added for V5
                DB::raw('MAX(vi.vendedor_nome) as vendedor_nome_pdv'),
            ])
            ->groupBy('vi.store_pdv_id', 'vi.canal', 'vi.id_operacao');

        $paymentAgg = DB::table('pdv_venda_pagamentos as vp')
            ->select([
                'vp.store_pdv_id',
                'vp.canal',
                'vp.id_operacao',
                DB::raw('COUNT(*) as pagamentos_count'),
                DB::raw('COALESCE(SUM(vp.valor), 0) as pagamentos_valor_total'),
            ])
            ->groupBy('vp.store_pdv_id', 'vp.canal', 'vp.id_operacao');

        $query = DB::table('pdv_vendas as v')
            ->leftJoin('stores as s', 'v.store_id', '=', 's.id')
            ->leftJoin('pdv_lojas as pl', 'v.store_pdv_id', '=', 'pl.id_ponto_venda')
            ->leftJoinSub($itemAgg, 'it', function ($join): void {
                $join->on('it.store_pdv_id', '=', 'v.store_pdv_id')
                    ->on('it.canal', '=', 'v.canal')
                    ->on('it.id_operacao', '=', 'v.id_operacao');
            })
            // V5 Refactor: Try joining by GUID first (direct to users table), then fallback to mapping
            ->leftJoin('users as u_by_guid', 'u_by_guid.guid', '=', 'it.vendedor_guid')
            ->leftJoin('pdv_usuarios as u_legacy', 'u_legacy.guid_usuario', '=', 'it.vendedor_guid')
            ->leftJoin('pdv_user_mappings as pum', function ($join): void {
                $join->on('pum.store_pdv_id', '=', 'v.store_pdv_id')
                    ->on('pum.pdv_user_id', '=', 'it.vendedor_pdv_id');
            })
            ->leftJoin('users as u_map', 'pum.user_id', '=', 'u_map.id')
            ->leftJoinSub($paymentAgg, 'pg', function ($join): void {
                $join->on('pg.store_pdv_id', '=', 'v.store_pdv_id')
                    ->on('pg.canal', '=', 'v.canal')
                    ->on('pg.id_operacao', '=', 'v.id_operacao');
            })
            ->select([
                'v.id',
                'v.store_id',
                's.name as store_name',
                's.razao_social as store_razao_social',
                's.cnpj as store_cnpj',
                'v.store_pdv_id',
                'pl.nome_padronizado as store_pdv_name',
                'v.id_operacao',
                'v.canal',
                'v.id_turno',
                'v.turno_seq',
                'v.data_hora',
                'v.total',
                DB::raw('COALESCE(u_by_guid.name, u_map.name, u_legacy.nome_padronizado, it.vendedor_nome_pdv) as seller_name'),
                DB::raw('COALESCE(u_by_guid.email, u_map.email, u_legacy.email) as seller_email'),
                DB::raw('COALESCE(u_by_guid.whatsapp, u_map.whatsapp) as seller_whatsapp'),
                DB::raw('COALESCE(u_by_guid.avatar_url, u_map.avatar_url) as seller_avatar_url'),
                DB::raw('COALESCE(u_by_guid.hire_date, u_map.hire_date) as seller_hire_date'),
                DB::raw('COALESCE(it.itens_count, 0) as itens_count'),
                DB::raw('COALESCE(it.itens_qtd_total, 0) as itens_qtd_total'),
                DB::raw('COALESCE(it.itens_valor_total, 0) as itens_valor_total'),
                DB::raw('COALESCE(pg.pagamentos_count, 0) as pagamentos_count'),
                DB::raw('COALESCE(pg.pagamentos_valor_total, 0) as pagamentos_valor_total'),
                'v.erp_operacao_uuid',
                'v.erp_loja_uuid',
                'v.nfce_chave',
                'v.nfce_modelo',
                'v.nfce_numero',
                'v.nfce_serie',
                'v.cliente_cpf',
                'v.signature_hash',
            ])
            ->whereBetween('v.data_hora', [$from->toDateTimeString(), $to->toDateTimeString()]);

        $this->applyStoreScopeToQuery($query, $scope, 'v');

        if (!empty($validated['canal'])) {
            $query->where('v.canal', (string) $validated['canal']);
        }
        if (!empty($validated['id_turno'])) {
            $query->where('v.id_turno', (string) $validated['id_turno']);
        }
        if (!empty($validated['turno_seq'])) {
            $query->where('v.turno_seq', (int) $validated['turno_seq']);
        }
        if (isset($validated['min_total'])) {
            $query->where('v.total', '>=', (float) $validated['min_total']);
        }
        if (isset($validated['max_total'])) {
            $query->where('v.total', '<=', (float) $validated['max_total']);
        }
        if (isset($validated['vendedor_id'])) {
            $vendedorId = (int) $validated['vendedor_id'];
            $query->whereExists(function ($sub) use ($vendedorId): void {
                $sub->selectRaw('1')
                    ->from('pdv_venda_itens as vi')
                    ->whereColumn('vi.store_pdv_id', 'v.store_pdv_id')
                    ->whereColumn('vi.canal', 'v.canal')
                    ->whereColumn('vi.id_operacao', 'v.id_operacao')
                    ->where('vi.vendedor_pdv_id', $vendedorId);
            });
        }
        if (isset($validated['id_finalizador']) || !empty($validated['meio_pagamento'])) {
            $isMysqlConnection = DB::connection()->getDriverName() === 'mysql';
            $idFinalizador = isset($validated['id_finalizador']) ? (int) $validated['id_finalizador'] : null;
            $meioPagamento = null;
            if (!empty($validated['meio_pagamento'])) {
                $meioPagamentoRaw = trim((string) $validated['meio_pagamento']);
                $meioPagamento = $meioPagamentoRaw !== '' ? $meioPagamentoRaw : null;
            }

            $query->whereExists(function ($sub) use ($idFinalizador, $meioPagamento, $isMysqlConnection): void {
                $sub->selectRaw('1')
                    ->from('pdv_venda_pagamentos as vp')
                    ->whereColumn('vp.store_pdv_id', 'v.store_pdv_id')
                    ->whereColumn('vp.canal', 'v.canal')
                    ->whereColumn('vp.id_operacao', 'v.id_operacao');

                if ($idFinalizador !== null) {
                    $sub->where('vp.id_finalizador', $idFinalizador);
                }

                if ($meioPagamento !== null) {
                    if ($isMysqlConnection) {
                        // meio_pagamento is utf8mb4_unicode_ci on MySQL (case-insensitive by collation).
                        $sub->where('vp.meio_pagamento', $meioPagamento);
                    } else {
                        $sub->whereRaw('LOWER(vp.meio_pagamento) = LOWER(?)', [$meioPagamento]);
                    }
                }
            });
        }

        $summaryBase = clone $query;
        $totalVendas = (int) (clone $summaryBase)->count('v.id');
        $totalVendido = (float) ((clone $summaryBase)->sum('v.total') ?? 0);

        $sortDirection = (string) ($validated['sort'] ?? 'desc');
        $perPage = (int) ($validated['per_page'] ?? 25);
        $paginator = $query
            ->orderBy('v.data_hora', $sortDirection)
            ->orderBy('v.id', $sortDirection)
            ->paginate($perPage);

        $rows = collect($paginator->items())
            ->map(fn($row): array => [
                'store_id' => $row->store_id !== null ? (int) $row->store_id : null,
                'store_name' => $row->store_name,
                'store_razao_social' => $row->store_razao_social ?? null,
                'store_cnpj' => $row->store_cnpj ?? null,
                'store_pdv_id' => (int) $row->store_pdv_id,
                'seller_name' => $row->seller_name ?? null,
                'seller_whatsapp' => $row->seller_whatsapp ?? null,
                'seller_avatar_url' => $row->seller_avatar_url ?? null,
                'seller_hire_date' => $row->seller_hire_date ?? null,
                'id_operacao' => (int) $row->id_operacao,
                'canal' => (string) ($row->canal ?? 'HIPER_CAIXA'),
                'id_turno' => $row->id_turno,
                'turno_seq' => $row->turno_seq ?? null,
                'data_hora' => $this->toIso8601($row->data_hora),
                'total' => (float) $row->total,
                'itens' => [
                    'qtd_linhas' => (int) $row->itens_count,
                    'qtd_total' => (float) $row->itens_qtd_total,
                    'valor_total' => (float) $row->itens_valor_total,
                ],
                'fiscal' => [
                    'nfce' => [
                        'chave' => $row->nfce_chave ?? null,
                        'modelo' => $row->nfce_modelo ?? null,
                        'numero' => $row->nfce_numero ?? null,
                        'serie' => $row->nfce_serie ?? null,
                    ],
                    'cliente_cpf' => $row->cliente_cpf ?? null,
                    'signature_hash' => $row->signature_hash ?? null,
                    'erp_operacao_uuid' => $row->erp_operacao_uuid ?? null,
                ],
                'pagamentos' => [
                    'qtd_linhas' => (int) $row->pagamentos_count,
                    'valor_total' => (float) $row->pagamentos_valor_total,
                ],
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'summary' => [
                'total_vendas' => $totalVendas,
                'total_vendido' => round($totalVendido, 2),
            ],
            'filters' => [
                'store_id' => $scope['store_id'],
                'store_pdv_id' => $scope['store_pdv_id'],
                'store_alias' => $scope['store_alias'],
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'vendedor_id' => isset($validated['vendedor_id']) ? (int) $validated['vendedor_id'] : null,
                'canal' => $validated['canal'] ?? null,
                'id_turno' => $validated['id_turno'] ?? null,
                'id_finalizador' => isset($validated['id_finalizador']) ? (int) $validated['id_finalizador'] : null,
                'meio_pagamento' => $validated['meio_pagamento'] ?? null,
                'sort' => $sortDirection,
            ],
            'meta' => $this->meta($paginator),
        ]);
    }

    /**
     * Detalhe de uma venda (itens + pagamentos)
     *
     * Retorna o extrato detalhado (linhas de itens e pagamentos) de uma venda
     * persistida pelo PDV Sync v3.
     *
     * @authenticated
     * @queryParam store_id integer ID da loja interna (`stores.id`). Obrigatorio se `store_pdv_id` nao for informado. Example: 8
     * @queryParam store_pdv_id integer ID da loja no PDV (`store.id_ponto_venda`). Obrigatorio se `store_id` nao for informado. Example: 9
     * @queryParam store_alias string Alias da loja PDV para desambiguar quando `store_pdv_id` colide. Example: mata-atlantica
     * @queryParam canal string required Canal da venda. Valores: `HIPER_CAIXA`, `HIPER_LOJA`. Example: HIPER_CAIXA
     * @queryParam id_operacao integer required ID da operacao no PDV. Example: 45949
     *
     * @response 200 {
     *   "data": {
     *     "filters": {
     *       "store_id": 8,
     *       "store_pdv_id": 9,
     *       "store_alias": "mata-atlantica",
     *       "canal": "HIPER_CAIXA",
     *       "id_operacao": 45949
     *     },
     *     "venda": {
     *       "store_id": 8,
     *       "store_pdv_id": 9,
     *       "canal": "HIPER_CAIXA",
     *       "id_operacao": 45949,
     *       "id_turno": "A2DB8C59-5451-492F-85F7-8540BFADEE75",
     *       "data_hora": "2026-02-12T20:44:08+00:00",
     *       "total": 22.5
     *     },
     *     "itens": [
     *       {
     *         "line_id": 47563,
     *         "line_no": 1,
     *         "id_produto": 3602,
     *         "codigo_barras": "5361",
     *         "nome_produto": "Pelicula de Camera Iphone 14 ProMax",
     *         "qtd": 1,
     *         "preco_unit": 22.5,
     *         "total": 22.5,
     *         "desconto": 7.5,
     *         "vendedor_pdv_id": 46,
     *         "vendedor_nome": "Bianca Brasil",
     *         "vendedor_login": "biancabrasil",
     *         "vendedor_user_id": 19
     *       }
     *     ],
     *     "pagamentos": [
     *       {
     *         "line_id": 45745,
     *         "line_no": 1,
     *         "id_finalizador": 4,
     *         "meio_pagamento": "Cartao de credito",
     *         "valor": 22.5,
     *         "troco": 0,
     *         "parcelas": 1
     *       }
     *     ],
     *     "summary": {
     *       "itens": {"qtd_linhas": 1, "qtd_total": 1, "valor_total": 22.5, "desconto_total": 7.5},
     *       "pagamentos": {"qtd_linhas": 1, "valor_total": 22.5, "troco_total": 0}
     *     }
     *   },
     *   "meta": {
     *     "request_id": "req-123",
     *     "timestamp": "2026-02-12T10:00:00+00:00"
     *   }
     * }
     * @response 403 {"message":"Voce nao tem acesso a esta loja."}
     * @response 404 {"message":"Venda nao encontrada."}
     * @response 422 {"message":"The given data was invalid.","errors":{"store":["Informe store_id ou store_pdv_id."]}}
     */
    public function vendaDetalhe(PdvReportsVendaDetalheRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $storeId = isset($validated['store_id']) ? (int) $validated['store_id'] : null;
        $storePdvId = isset($validated['store_pdv_id']) ? (int) $validated['store_pdv_id'] : null;
        $storeAlias = isset($validated['store_alias']) ? trim((string) $validated['store_alias']) : null;
        if ($storeId === null && $storePdvId === null) {
            throw ValidationException::withMessages([
                'store' => ['Informe store_id ou store_pdv_id.'],
            ]);
        }

        $scope = $this->resolveStoreScope($request, $storeId, $storePdvId, $storeAlias);

        $canal = (string) $validated['canal'];
        $idOperacao = (int) $validated['id_operacao'];

        $vendaQuery = DB::table('pdv_vendas as v')
            ->leftJoin('stores as s', 'v.store_id', '=', 's.id')
            ->leftJoin('pdv_lojas as pl', 'v.store_pdv_id', '=', 'pl.id_ponto_venda')
            ->select([
                'v.store_id',
                's.name as store_name', // Internal Store Name
                's.razao_social as store_razao_social',
                's.cnpj as store_cnpj',
                'v.store_pdv_id',
                'pl.nome_padronizado as store_pdv_name', // PDV Store Name
                'v.canal',
                'v.id_operacao',
                'v.id_turno',
                'v.turno_seq',
                'v.data_hora',
                'v.total',
                'v.erp_operacao_uuid',
                'v.erp_loja_uuid',
                'v.nfce_chave',
                'v.nfce_modelo',
                'v.nfce_numero',
                'v.nfce_serie',
                'v.cliente_cpf',
                'v.signature_hash',
            ])
            ->where('v.canal', $canal)
            ->where('v.id_operacao', $idOperacao);

        $this->applyStoreScopeToQuery($vendaQuery, $scope, 'v');

        $venda = $vendaQuery->first();
        if ($venda === null) {
            return $this->notFound('Venda nao encontrada.');
        }

        $resolvedStorePdvId = (int) $venda->store_pdv_id;
        $resolvedCanal = (string) ($venda->canal ?? 'HIPER_CAIXA');
        $resolvedIdOperacao = (int) $venda->id_operacao;

        $itensRows = DB::table('pdv_venda_itens as vi')
            ->select([
                'vi.id',
                'vi.line_id',
                'vi.line_no',
                'vi.id_produto',
                'vi.codigo_barras',
                'vi.nome_produto',
                'vi.qtd',
                'vi.preco_unit',
                'vi.total',
                'vi.desconto',
                'vi.vendedor_pdv_id',
                'vi.vendedor_nome',
                'vi.vendedor_login',
                'vi.vendedor_user_id',
                'vi.vendedor_guid', // V5 UUID
            ])
            ->where('vi.store_pdv_id', $resolvedStorePdvId)
            ->where('vi.canal', $resolvedCanal)
            ->where('vi.id_operacao', $resolvedIdOperacao)
            ->orderBy('vi.line_no')
            ->orderBy('vi.id')
            ->leftJoin('pdv_user_mappings as pum', function ($join): void {
                $join->on('pum.store_pdv_id', '=', 'vi.store_pdv_id')
                    ->on('pum.pdv_user_id', '=', 'vi.vendedor_pdv_id');
            })
            ->leftJoin('users as u', 'pum.user_id', '=', 'u.id')
            ->leftJoin('users as u_by_guid', 'u_by_guid.guid', '=', 'vi.vendedor_guid')
            ->addSelect([
                DB::raw('COALESCE(u_by_guid.name, u.name) as vendedor_name_normalized'),
                DB::raw('COALESCE(u_by_guid.whatsapp, u.whatsapp) as vendedor_whatsapp'),
                DB::raw('COALESCE(u_by_guid.avatar_url, u.avatar_url) as vendedor_avatar_url'),
                DB::raw('COALESCE(u_by_guid.hire_date, u.hire_date) as vendedor_hire_date'),
                'u.name as mapped_user_name',
            ])
            ->get();

        $pagamentosRows = DB::table('pdv_venda_pagamentos as vp')
            ->select([
                'vp.id',
                'vp.line_id',
                'vp.line_no',
                'vp.id_finalizador',
                'vp.meio_pagamento',
                'vp.valor',
                'vp.troco',
                'vp.parcelas',
            ])
            ->where('vp.store_pdv_id', $resolvedStorePdvId)
            ->where('vp.canal', $resolvedCanal)
            ->where('vp.id_operacao', $resolvedIdOperacao)
            ->orderBy('vp.line_no')
            ->orderBy('vp.id')
            ->get();

        $itensCount = (int) $itensRows->count();
        $itensQtdTotal = round((float) ($itensRows->sum('qtd') ?? 0), 3);
        $itensValorTotal = round((float) ($itensRows->sum('total') ?? 0), 2);
        $itensDescontoTotal = round((float) ($itensRows->sum('desconto') ?? 0), 2);

        $pagamentosCount = (int) $pagamentosRows->count();
        $pagamentosValorTotal = round((float) ($pagamentosRows->sum('valor') ?? 0), 2);
        $pagamentosTrocoTotal = round((float) ($pagamentosRows->sum('troco') ?? 0), 2);

        $itens = $itensRows->map(static fn(object $row): array => [
            'line_id' => $row->line_id !== null ? (int) $row->line_id : null,
            'line_no' => (int) $row->line_no,
            'id_produto' => $row->id_produto !== null ? (int) $row->id_produto : null,
            'codigo_barras' => $row->codigo_barras,
            'nome_produto' => $row->nome_produto,
            'qtd' => (float) $row->qtd,
            'preco_unit' => (float) $row->preco_unit,
            'total' => (float) $row->total,
            'desconto' => (float) $row->desconto,
            'valor_original' => round((float) ($row->total + $row->desconto), 2),
            'preco_original' => $row->qtd > 0 ? round((float) (($row->total + $row->desconto) / $row->qtd), 2) : 0.0,
            'vendedor_pdv_id' => $row->vendedor_pdv_id !== null ? (int) $row->vendedor_pdv_id : null,
            'vendedor_guid' => $row->vendedor_guid ?? null,
            'vendedor_nome' => $row->vendedor_name_normalized ?? $row->vendedor_nome, // Enriched name
            'vendedor_login' => $row->vendedor_login,
            'vendedor_user_id' => $row->vendedor_user_id !== null ? (int) $row->vendedor_user_id : null,
            'vendedor_whatsapp' => $row->vendedor_whatsapp ?? null,
            'vendedor_avatar_url' => $row->vendedor_avatar_url ?? null,
            'vendedor_hire_date' => $row->vendedor_hire_date ?? null,
        ])->values()->all();

        $pagamentos = $pagamentosRows->map(static fn(object $row): array => [
            'line_id' => $row->line_id !== null ? (int) $row->line_id : null,
            'line_no' => (int) $row->line_no,
            'id_finalizador' => (int) $row->id_finalizador,
            'meio_pagamento' => $row->meio_pagamento,
            'valor' => (float) $row->valor,
            'troco' => (float) $row->troco,
            'parcelas' => $row->parcelas !== null ? (int) $row->parcelas : null,
        ])->values()->all();

        return $this->success([
            'filters' => [
                'store_id' => $scope['store_id'],
                'store_pdv_id' => $scope['store_pdv_id'],
                'store_alias' => $scope['store_alias'],
                'canal' => $resolvedCanal,
                'id_operacao' => $resolvedIdOperacao,
            ],
            'venda' => [
                'store_id' => $venda->store_id !== null ? (int) $venda->store_id : null,
                'store_name' => $venda->store_name ?? null,
                'store_pdv_id' => $resolvedStorePdvId,
                'store_pdv_name' => $venda->store_pdv_name ?? null,
                'store_cnpj' => $venda->store_cnpj ?? null,
                'store_razao_social' => $venda->store_razao_social ?? null,
                'canal' => $resolvedCanal,
                'id_operacao' => $resolvedIdOperacao,
                'id_turno' => $venda->id_turno,
                'turno_seq' => $venda->turno_seq ?? null,
                'data_hora' => $this->toIso8601($venda->data_hora),
                'total' => (float) $venda->total,
                'erp_operacao_uuid' => $venda->erp_operacao_uuid ?? null,
                'erp_loja_uuid' => $venda->erp_loja_uuid ?? null,
                'fiscal' => [
                    'nfce' => [
                        'chave' => $venda->nfce_chave ?? null,
                        'modelo' => $venda->nfce_modelo ?? null,
                        'numero' => $venda->nfce_numero ?? null,
                        'serie' => $venda->nfce_serie ?? null,
                    ],
                    'cliente_cpf' => $venda->cliente_cpf ?? null,
                    'signature_hash' => $venda->signature_hash ?? null,
                ],
            ],
            'itens' => $itens,
            'pagamentos' => $pagamentos,
            'summary' => [
                'itens' => [
                    'qtd_linhas' => $itensCount,
                    'qtd_total' => $itensQtdTotal,
                    'valor_total' => $itensValorTotal,
                    'desconto_total' => $itensDescontoTotal,
                ],
                'pagamentos' => [
                    'qtd_linhas' => $pagamentosCount,
                    'valor_total' => $pagamentosValorTotal,
                    'troco_total' => $pagamentosTrocoTotal,
                ],
            ],
        ]);
    }

    /**
     * Ranking de vendedores por periodo
     *
     * Consolida vendas por vendedor a partir de `pdv_venda_itens` + `pdv_vendas`.
     *
     * Regras de periodo:
     * - Se `from/to` forem enviados, eles prevalecem sobre `mode`
     * - Sem `from/to`, usa `mode` + `reference_date`
     *
     * @authenticated
     * @queryParam mode string Modo de periodo: `daily`, `weekly`, `monthly`. Default: `monthly`. Example: monthly
     * @queryParam reference_date string Data base para `mode` (`YYYY-MM-DD`). Example: 2026-02-12
     * @queryParam from string Data inicial custom (`YYYY-MM-DD`). Example: 2026-02-01
     * @queryParam to string Data final custom (`YYYY-MM-DD`). Example: 2026-02-12
     * @queryParam store_id integer Filtrar por loja interna. Example: 1
     * @queryParam store_pdv_id integer Filtrar por loja PDV. Example: 13
     * @queryParam store_alias string Alias da loja PDV para desambiguar quando `store_pdv_id` colide. Example: Loja 8 - MC Mata Atlântica
     * @queryParam canal string Filtrar por canal (`HIPER_CAIXA` ou `HIPER_LOJA`). Example: HIPER_CAIXA
     * @queryParam limit integer Limite de linhas do ranking (1-200). Default: 50. Example: 20
     *
     * @response 200 {
     *   "data": {
     *     "mode": "monthly",
     *     "period": {
     *       "from": "2026-02-01T00:00:00+00:00",
     *       "to": "2026-02-28T23:59:59+00:00"
     *     },
     *     "filters": {"store_id": 1, "store_pdv_id": 13, "canal": null, "limit": 50},
     *     "summary": {"vendedores": 2, "total_vendido": 10000, "qtd_vendas": 120, "total_itens": 280},
     *     "ranking": [{"position": 1, "vendedor_id": 80, "vendedor_nome": "Daren", "qtd_vendas": 70, "total_vendido": 6200, "total_itens": 170}]
     *   },
     *   "meta": {"request_id": "req-123", "timestamp": "2026-02-12T10:00:00+00:00"}
     * }
     * @response 403 {"message":"Voce nao tem acesso a esta loja."}
     * @response 422 {"message":"The given data was invalid.","errors":{"mode":["The selected mode is invalid."]}}
     */
    public function rankingVendedores(PdvReportsRankingVendedoresRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $storeId = isset($validated['store_id']) ? (int) $validated['store_id'] : null;
        $storePdvId = isset($validated['store_pdv_id']) ? (int) $validated['store_pdv_id'] : null;
        $storeAlias = isset($validated['store_alias']) ? trim((string) $validated['store_alias']) : null;
        $scope = $this->resolveStoreScope($request, $storeId, $storePdvId, $storeAlias);

        [$from, $to, $mode] = $this->resolveRankingPeriod($validated);
        $limit = (int) ($validated['limit'] ?? 50);

        $query = DB::table('pdv_venda_itens as vi')
            ->join('pdv_vendas as v', function ($join): void {
                $join->on('v.store_pdv_id', '=', 'vi.store_pdv_id')
                    ->on('v.canal', '=', 'vi.canal')
                    ->on('v.id_operacao', '=', 'vi.id_operacao');
            })
            // V5: Prefer joining by GUID if available, else legacy ID
            ->leftJoin('pdv_usuarios as pu', function ($join) {
                $join->on('pu.guid_usuario', '=', 'vi.vendedor_guid')
                    ->orOn('pu.id_usuario_hiper', '=', 'vi.vendedor_pdv_id');
            })
            // Use GUID as primary grouping key if present (for global ranking), else ID
            ->selectRaw('COALESCE(vi.vendedor_guid, CAST(vi.vendedor_pdv_id as CHAR)) as vendedor_id')
            ->selectRaw('MAX(COALESCE(pu.nome_padronizado, pu.nome_hiper, vi.vendedor_nome)) as vendedor_nome')
            ->selectRaw('COUNT(DISTINCT v.id) as qtd_vendas')
            ->selectRaw('COALESCE(SUM(vi.total), 0) as total_vendido')
            ->selectRaw('COALESCE(SUM(vi.qtd), 0) as total_itens')
            ->where(function ($q) {
                $q->whereNotNull('vi.vendedor_pdv_id')
                    ->orWhereNotNull('vi.vendedor_guid');
            })
            ->whereBetween('v.data_hora', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->groupBy(DB::raw('COALESCE(vi.vendedor_guid, CAST(vi.vendedor_pdv_id as CHAR))'));

        $this->applyStoreScopeToQuery($query, $scope, 'v');

        if (!empty($validated['canal'])) {
            $query->where('v.canal', (string) $validated['canal']);
        }

        // Calculate summary stats before limiting
        // We use fromSub because the original query is grouped
        $summaryQuery = DB::query()->fromSub(clone $query, 'ranking_base');

        $summaryTotalVendedores = $summaryQuery->count();
        // Note: sum() on QueryBuilder returns sum of column. 
        // In the subquery 'total_vendido', 'qtd_vendas', 'total_itens' are the columns we want to sum.
        $summaryTotalVendido = (float) ($summaryQuery->sum('total_vendido') ?? 0);
        $summaryQtdVendas = (int) ($summaryQuery->sum('qtd_vendas') ?? 0);
        $summaryTotalItens = (float) ($summaryQuery->sum('total_itens') ?? 0);

        $ranking = $query
            ->orderByDesc('total_vendido')
            ->orderBy('vi.vendedor_pdv_id')
            ->limit($limit)
            ->get()
            ->values()
            ->map(function ($row, int $index): array {
                return [
                    'position' => $index + 1,
                    'vendedor_id' => $row->vendedor_id !== null ? (int) $row->vendedor_id : null,
                    'vendedor_nome' => $row->vendedor_nome ?? 'Vendedor sem nome',
                    'qtd_vendas' => (int) $row->qtd_vendas,
                    'total_vendido' => round((float) $row->total_vendido, 2),
                    'total_itens' => round((float) $row->total_itens, 3),
                ];
            });

        return $this->success([
            'mode' => $mode,
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'filters' => [
                'store_id' => $scope['store_id'],
                'store_pdv_id' => $scope['store_pdv_id'],
                'store_alias' => $scope['store_alias'],
                'canal' => $validated['canal'] ?? null,
                'limit' => $limit,
            ],
            'summary' => [
                'vendedores' => $summaryTotalVendedores,
                'total_vendido' => round($summaryTotalVendido, 2),
                'qtd_vendas' => $summaryQtdVendas,
                'total_itens' => round($summaryTotalItens, 3),
            ],
            'ranking' => $ranking->all(),
        ]);
    }

    /**
     * Ranking vendedor x loja por periodo
     *
     * Consolida performance por combinacao `store` + `vendedor`, com paginacao.
     *
     * @authenticated
     * @queryParam from string required Data inicial (`YYYY-MM-DD`). Example: 2026-02-01
     * @queryParam to string required Data final (`YYYY-MM-DD`). Example: 2026-02-12
     * @queryParam store_id integer Filtrar por loja interna. Example: 1
     * @queryParam store_pdv_id integer Filtrar por loja PDV. Example: 13
     * @queryParam store_alias string Alias da loja PDV para desambiguar quando `store_pdv_id` colide. Example: Loja 8 - MC Mata Atlântica
     * @queryParam vendedor_id integer Filtrar por vendedor. Example: 80
     * @queryParam canal string Filtrar por canal (`HIPER_CAIXA` ou `HIPER_LOJA`). Example: HIPER_CAIXA
     * @queryParam sort_by string Campo de ordenacao: `total_vendido`, `qtd_vendas`, `total_itens`. Default: `total_vendido`. Example: total_vendido
     * @queryParam sort string Direcao da ordenacao: `asc` ou `desc`. Default: `desc`. Example: desc
     * @queryParam per_page integer Tamanho da pagina (1-200). Default: 50. Example: 50
     *
     * @response 200 {
     *   "data": [
     *     {
     *       "position": 1,
     *       "store_id": 2,
     *       "store_pdv_id": 14,
     *       "store_nome": "Loja Centro",
     *       "vendedor_id": 80,
     *       "vendedor_nome": "Daren",
     *       "qtd_vendas": 32,
     *       "total_vendido": 8450,
     *       "total_itens": 126
     *     }
     *   ],
     *   "summary": {"linhas": 1, "total_vendido": 8450, "qtd_vendas": 32, "total_itens": 126},
     *   "filters": {"canal": "HIPER_CAIXA", "sort_by": "total_vendido", "sort": "desc"},
     *   "meta": {
     *     "request_id": "req-123",
     *     "timestamp": "2026-02-12T10:00:00+00:00",
     *     "pagination": {"total": 1, "per_page": 50, "current_page": 1, "last_page": 1}
     *   }
     * }
     * @response 403 {"message":"Voce nao tem acesso a esta loja."}
     * @response 422 {"message":"The given data was invalid.","errors":{"from":["The from field is required."]}}
     */
    public function rankingVendedorLoja(PdvReportsRankingVendedorLojaRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $storeId = isset($validated['store_id']) ? (int) $validated['store_id'] : null;
        $storePdvId = isset($validated['store_pdv_id']) ? (int) $validated['store_pdv_id'] : null;
        $storeAlias = isset($validated['store_alias']) ? trim((string) $validated['store_alias']) : null;
        $scope = $this->resolveStoreScope($request, $storeId, $storePdvId, $storeAlias);

        $from = CarbonImmutable::parse((string) $validated['from'])->startOfDay();
        $to = CarbonImmutable::parse((string) $validated['to'])->endOfDay();
        $sortBy = (string) ($validated['sort_by'] ?? 'total_vendido');
        $sortDirection = (string) ($validated['sort'] ?? 'desc');
        $perPage = (int) ($validated['per_page'] ?? 50);

        $query = DB::table('pdv_venda_itens as vi')
            ->join('pdv_vendas as v', function ($join): void {
                $join->on('v.store_pdv_id', '=', 'vi.store_pdv_id')
                    ->on('v.canal', '=', 'vi.canal')
                    ->on('v.id_operacao', '=', 'vi.id_operacao');
            })
            ->leftJoin('pdv_usuarios as pu', 'pu.id_usuario_hiper', '=', 'vi.vendedor_pdv_id')
            ->leftJoin('stores as s', 's.id', '=', 'v.store_id')
            ->whereNotNull('vi.vendedor_pdv_id')
            ->whereBetween('v.data_hora', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->selectRaw('v.store_id as store_id')
            ->selectRaw('v.store_pdv_id as store_pdv_id')
            ->selectRaw("MAX(COALESCE(s.name, CONCAT('Loja PDV ', v.store_pdv_id))) as store_nome")
            ->selectRaw('vi.vendedor_pdv_id as vendedor_id')
            ->selectRaw('MAX(COALESCE(pu.nome_padronizado, pu.nome_hiper, vi.vendedor_nome)) as vendedor_nome')
            ->selectRaw('COUNT(DISTINCT v.id) as qtd_vendas')
            ->selectRaw('COALESCE(SUM(vi.total), 0) as total_vendido')
            ->selectRaw('COALESCE(SUM(vi.qtd), 0) as total_itens')
            ->groupBy('v.store_id', 'v.store_pdv_id', 'vi.vendedor_pdv_id');

        $this->applyStoreScopeToQuery($query, $scope, 'v');

        if (!empty($validated['canal'])) {
            $query->where('v.canal', (string) $validated['canal']);
        }
        if (isset($validated['vendedor_id'])) {
            $query->where('vi.vendedor_pdv_id', (int) $validated['vendedor_id']);
        }

        $summaryQuery = DB::query()->fromSub(clone $query, 'ranking_base');
        $totalRows = (int) (clone $summaryQuery)->count();
        $totalVendido = (float) ((clone $summaryQuery)->sum('total_vendido') ?? 0);
        $totalItens = (float) ((clone $summaryQuery)->sum('total_itens') ?? 0);
        $totalVendas = (int) ((clone $summaryQuery)->sum('qtd_vendas') ?? 0);

        $paginator = $query
            ->orderBy($sortBy, $sortDirection)
            ->orderBy('store_pdv_id')
            ->orderBy('vendedor_id')
            ->paginate($perPage);

        $offset = ($paginator->currentPage() - 1) * $paginator->perPage();
        $rows = collect($paginator->items())
            ->values()
            ->map(function ($row, int $index) use ($offset): array {
                return [
                    'position' => $offset + $index + 1,
                    'store_id' => $row->store_id !== null ? (int) $row->store_id : null,
                    'store_pdv_id' => (int) $row->store_pdv_id,
                    'store_nome' => $row->store_nome,
                    'vendedor_id' => $row->vendedor_id !== null ? (int) $row->vendedor_id : null,
                    'vendedor_nome' => $row->vendedor_nome ?? 'Vendedor sem nome',
                    'qtd_vendas' => (int) $row->qtd_vendas,
                    'total_vendido' => round((float) $row->total_vendido, 2),
                    'total_itens' => round((float) $row->total_itens, 3),
                ];
            })
            ->all();

        return response()->json([
            'data' => $rows,
            'summary' => [
                'linhas' => $totalRows,
                'total_vendido' => round($totalVendido, 2),
                'qtd_vendas' => $totalVendas,
                'total_itens' => round($totalItens, 3),
            ],
            'filters' => [
                'store_id' => $scope['store_id'],
                'store_pdv_id' => $scope['store_pdv_id'],
                'store_alias' => $scope['store_alias'],
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'vendedor_id' => isset($validated['vendedor_id']) ? (int) $validated['vendedor_id'] : null,
                'canal' => $validated['canal'] ?? null,
                'sort_by' => $sortBy,
                'sort' => $sortDirection,
            ],
            'meta' => $this->meta($paginator),
        ]);
    }

    /**
     * @return array{store_id:int|null,store_pdv_id:int|null,store_alias:string|null,allowed_store_ids:array<int, int>|null}
     */
    private function resolveStoreScope(Request $request, ?int $storeId, ?int $storePdvId, ?string $storeAlias = null): array
    {
        $user = $request->user();
        if (!$user) {
            abort(401, 'Unauthenticated.');
        }

        $allowedStoreIds = null;
        if (!$user->isSuperAdmin()) {
            $allowedStoreIds = $user->storeUsers()
                ->pluck('store_id')
                ->map(static fn(mixed $value): int => (int) $value)
                ->filter(static fn(int $value): bool => $value > 0)
                ->unique()
                ->values()
                ->all();

            if ($allowedStoreIds === []) {
                abort(403, 'Usuario sem acesso a lojas.');
            }
        }

        if ($storeId !== null && !$user->isSuperAdmin() && !$user->hasAccessToStore($storeId)) {
            abort(403, 'Voce nao tem acesso a esta loja.');
        }

        $storeAlias = $storeAlias !== null && trim($storeAlias) !== '' ? trim($storeAlias) : null;

        if ($storePdvId !== null) {
            $resolver = app(PdvStoreResolver::class);
            $mappings = $resolver->activeMappingsByPdvId($storePdvId);

            if ($storeAlias !== null) {
                $aliasFiltered = $mappings->filter(static function (object $mapping) use ($storeAlias): bool {
                    $mappingAlias = trim((string) ($mapping->alias ?? ''));
                    return $mappingAlias !== '' && mb_strtolower($mappingAlias) === mb_strtolower($storeAlias);
                })->values();

                if ($aliasFiltered->count() === 0) {
                    throw ValidationException::withMessages([
                        'store_alias' => ['Nao existe mapping ativo para este store_pdv_id + store_alias.'],
                    ]);
                }
                if ($aliasFiltered->count() > 1) {
                    throw ValidationException::withMessages([
                        'store_alias' => ['store_pdv_id + store_alias retornou mais de uma loja ativa.'],
                    ]);
                }

                $resolvedStoreId = (int) ($aliasFiltered->first()->store_id ?? 0);
                if ($resolvedStoreId <= 0) {
                    throw ValidationException::withMessages([
                        'store' => ['Mapping encontrado, mas sem store_id valido.'],
                    ]);
                }

                if ($storeId !== null && $storeId !== $resolvedStoreId) {
                    throw ValidationException::withMessages([
                        'store' => ['store_id e store_pdv_id + store_alias nao pertencem a mesma loja.'],
                    ]);
                }

                $storeId = $resolvedStoreId;
            } elseif ($storeId === null) {
                if ($mappings->count() > 1) {
                    throw ValidationException::withMessages([
                        'store_pdv_id' => [
                            'store_pdv_id ambiguo. Informe store_id ou store_alias para desambiguar.',
                        ],
                    ]);
                }

                if ($mappings->count() === 1) {
                    $resolvedStoreId = (int) ($mappings->first()->store_id ?? 0);
                    $storeId = $resolvedStoreId > 0 ? $resolvedStoreId : null;
                }
            } else {
                $belongsToStore = $mappings->contains(static function (object $mapping) use ($storeId): bool {
                    return (int) ($mapping->store_id ?? 0) === $storeId;
                });
                if (!$belongsToStore) {
                    throw ValidationException::withMessages([
                        'store' => ['store_id e store_pdv_id nao pertencem a mesma loja.'],
                    ]);
                }
            }

            if (!$user->isSuperAdmin() && ($storeId === null || !$user->hasAccessToStore($storeId))) {
                abort(403, 'Voce nao tem acesso a esta loja PDV.');
            }
        }

        return [
            'store_id' => $storeId,
            'store_pdv_id' => $storePdvId,
            'store_alias' => $storeAlias,
            'allowed_store_ids' => $allowedStoreIds,
        ];
    }

    /**
     * @param array{store_id:int|null,store_pdv_id:int|null,allowed_store_ids:array<int,int>|null} $scope
     */
    private function applyStoreScopeToQuery(QueryBuilder $query, array $scope, string $alias): void
    {
        $prefix = $alias !== '' ? $alias . '.' : '';

        if ($scope['store_id'] !== null) {
            $query->where($prefix . 'store_id', $scope['store_id']);
        }
        if ($scope['store_pdv_id'] !== null) {
            $query->where($prefix . 'store_pdv_id', $scope['store_pdv_id']);
        }

        if (
            $scope['store_id'] === null
            && $scope['store_pdv_id'] === null
            && is_array($scope['allowed_store_ids'])
        ) {
            $query->whereIn($prefix . 'store_id', $scope['allowed_store_ids']);
        }
    }

    /**
     * @param array<int, mixed> $validated
     * @return array{0:CarbonImmutable,1:CarbonImmutable,2:string}
     */
    private function resolveRankingPeriod(array $validated): array
    {
        if (!empty($validated['from']) || !empty($validated['to'])) {
            $from = !empty($validated['from'])
                ? CarbonImmutable::parse((string) $validated['from'])->startOfDay()
                : CarbonImmutable::now()->subDays(30)->startOfDay();
            $to = !empty($validated['to'])
                ? CarbonImmutable::parse((string) $validated['to'])->endOfDay()
                : CarbonImmutable::now()->endOfDay();

            return [$from, $to, (string) ($validated['mode'] ?? 'custom')];
        }

        $mode = (string) ($validated['mode'] ?? 'monthly');
        $reference = !empty($validated['reference_date'])
            ? CarbonImmutable::parse((string) $validated['reference_date'])
            : CarbonImmutable::now();

        if ($mode === 'daily') {
            return [$reference->startOfDay(), $reference->endOfDay(), $mode];
        }
        if ($mode === 'weekly') {
            return [$reference->startOfWeek(), $reference->endOfWeek(), $mode];
        }

        return [$reference->startOfMonth(), $reference->endOfMonth(), 'monthly'];
    }

    /**
     * @param Collection<int, object> $turnos
     * @return array<string, array{0:array<int, array<string, mixed>>,1:array<int, array<string, mixed>>,2:array<int, array<string, mixed>>}|array<string, array<int, array<string, mixed>>>
     */
    private function loadTurnoPagamentos(Collection $turnos): array
    {
        if ($turnos->isEmpty()) {
            return [];
        }

        $turnoIds = $turnos->pluck('id_turno')
            ->filter(static fn(mixed $value): bool => is_string($value) && trim($value) !== '')
            ->unique()
            ->values()
            ->all();
        if ($turnoIds === []) {
            return [];
        }

        $storePdvIds = $turnos->pluck('store_pdv_id')
            ->map(static fn(mixed $value): int => (int) $value)
            ->filter(static fn(int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();
        if ($storePdvIds === []) {
            return [];
        }

        $rows = DB::table('pdv_turno_pagamentos as tp')
            ->whereIn('tp.store_pdv_id', $storePdvIds)
            ->whereIn('tp.id_turno', $turnoIds)
            ->orderBy('tp.tipo')
            ->orderBy('tp.id_finalizador')
            ->get([
                'tp.store_pdv_id',
                'tp.id_turno',
                'tp.tipo',
                'tp.id_finalizador',
                'tp.meio_pagamento',
                'tp.total',
                'tp.qtd_vendas',
            ]);

        return $rows
            ->groupBy(fn($row): string => $this->turnoCompositeKey((int) $row->store_pdv_id, (string) $row->id_turno))
            ->map(function (Collection $items): array {
                $grouped = [
                    'sistema' => [],
                    'declarado' => [],
                    'falta' => [],
                ];

                foreach ($items as $row) {
                    $tipo = (string) $row->tipo;
                    if (!array_key_exists($tipo, $grouped)) {
                        continue;
                    }

                    $grouped[$tipo][] = [
                        'id_finalizador' => (int) $row->id_finalizador,
                        'meio_pagamento' => $row->meio_pagamento,
                        'total' => (float) $row->total,
                        'qtd_vendas' => (int) $row->qtd_vendas,
                    ];
                }

                return $grouped;
            })
            ->all();
    }

    private function turnoCompositeKey(int $storePdvId, string $idTurno): string
    {
        return $storePdvId . '|' . $idTurno;
    }

    private function toIso8601(mixed $dateTime): ?string
    {
        if ($dateTime === null || $dateTime === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $dateTime)->toIso8601String();
    }

    /**
     * Lista de Vendedores para Filtro
     *
     * Retorna lista de vendedores que já realizaram vendas na loja.
     * Prioriza nomes mapeados (usuários do sistema), depois nomes do PDV, e por fim ID.
     */
    /**
     * Lista de Vendedores para Filtro
     *
     * Retorna lista de vendedores que já realizaram vendas na loja (ou em todas as lojas permitidas).
     * Prioriza nomes mapeados (usuários do sistema), depois nomes do PDV, e por fim ID.
     */
    public function vendedores(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $targetStoreIds = [];

        // 1. Determine Scope (Specific Store or All Allowed)
        if ($request->has('store_id') && $request->input('store_id') !== null) {
            $storeId = (int) $request->input('store_id');
            if (!$user->isSuperAdmin() && !$user->hasAccessToStore($storeId)) {
                return $this->success([]); // Or 403, but empty list is safer for dropdowns
            }
            $targetStoreIds = [$storeId];
        } else {
            // Global Search: Get all allowed stores
            $targetStoreIds = $user->isSuperAdmin()
                ? DB::table('stores')->pluck('id')->toArray()
                : $user->allowedStores()->pluck('stores.id')->toArray();
        }

        if (empty($targetStoreIds)) {
            return $this->success([]);
        }

        // 2. Resolve PDV Store IDs from mappings
        $storeMappings = DB::table('pdv_store_mappings')
            ->whereIn('store_id', $targetStoreIds)
            ->where('active', true)
            ->get(); // distinct store_id -> pdv_store_id

        if ($storeMappings->isEmpty()) {
            return $this->success([]);
        }

        $pdvStoreIds = $storeMappings->pluck('pdv_store_id')->unique()->toArray();

        // Map pdv_store_id => store_name for context
        $storesMap = DB::table('stores')->whereIn('id', $storeMappings->pluck('store_id'))->pluck('name', 'id');
        $pdvToStoreName = [];
        foreach ($storeMappings as $map) {
            $pdvToStoreName[$map->pdv_store_id] = $storesMap[$map->store_id] ?? 'Loja Desconhecida';
        }

        // 3. Get Active User Mappings for these stores (to resolve names)
        $userMappings = DB::table('pdv_user_mappings')
            ->whereIn('store_pdv_id', $pdvStoreIds)
            ->where('active', true)
            ->get()
            ->groupBy('store_pdv_id'); // Group by Store to handle collisions if needed

        // 4. Get Actual Sellers from Sales History
        // We select distinct store_pdv_id + vendedor_pdv_id to handle same ID in different stores
        $sellers = DB::table('pdv_venda_itens')
            ->whereIn('store_pdv_id', $pdvStoreIds)
            ->select('store_pdv_id', 'vendedor_pdv_id')
            ->distinct()
            ->get();

        // 5. Fetch Raw PDV Users for fallback names
        $allSellerIds = $sellers->pluck('vendedor_pdv_id')->unique();
        $pdvUsers = DB::table('pdv_usuarios')
            ->whereIn('id_usuario_hiper', $allSellerIds)
            ->get()
            ->keyBy('id_usuario_hiper');

        $result = [];
        $processedKeys = []; // To deduplicate if same person exists in multiple stores? 
        // For now, let's list them contextualized: "Juan (Loja 1)", "Juan (Loja 2)"
        // But if filtering by ID=10 merges them, maybe we should just merge them if names match?
        // Let's list distinct combinations first.

        foreach ($sellers as $row) {
            $storePdvId = (int) $row->store_pdv_id;
            $sellerPdvId = (int) $row->vendedor_pdv_id;

            // Resolve Name
            $nome = "Vendedor #{$sellerPdvId}";
            $userId = null;
            $source = 'fallback';

            // Try Mapping for this specific store
            $map = isset($userMappings[$storePdvId])
                ? $userMappings[$storePdvId]->firstWhere('pdv_user_id', $sellerPdvId)
                : null;

            if ($map && $map->pdv_user_name) {
                $nome = $map->pdv_user_name;
                $userId = $map->user_id;
                $source = 'mapped';
            } elseif (isset($pdvUsers[$sellerPdvId])) {
                $u = $pdvUsers[$sellerPdvId];
                $nome = $u->nome_hiper ?? $u->nome_padronizado;
                $source = 'pdv_registry';
            }

            $nome = \Illuminate\Support\Str::title(\Illuminate\Support\Str::lower($nome));

            // Context logic: If global search, append store name ONLY if we have collisions?
            // Simpler: Just allow the FE to decide. We pass store_name.
            // Actually, for the dropdown, if we have "Joao" in Store A and "Joao" in Store B (both ID 10),
            // We return TWO rows. The FE might deduplicate by ID, which implicitly merges them.
            // If we want to support unique selection, we need unique IDs (composite), but the filter API uses Int.
            // So we will just return them. The FE can choose to show "Joao (Loja A)" and "Joao (Loja B)".

            // Unique Key for result array
            $key = $storePdvId . '|' . $sellerPdvId;

            $result[] = [
                'id' => (int) $sellerPdvId, // Keep as int for filter compatibility
                'nome' => $nome,
                'store_name' => $pdvToStoreName[$storePdvId] ?? '',
                'user_id' => $userId,
                'source' => $source,
                'unique_key' => $key // Frontend can use this for 'key' prop
            ];
        }

        // Deduplication Logic for Frontend Convenience:
        // If the user wants a simple list of names and doesn't care about Store separation in the dropdown
        // (since selecting ID 10 filters globally anyway), we can deduplicate by ID?
        // Risk: ID 10 is "Joao" in A and "Maria" in B. If we dedup by ID, we show "Joao". User selects "Joao", sees sales for "Maria" too.
        // This is dangerous.
        // Better to return all.

        // Sort by Name
        usort($result, fn($a, $b) => strcasecmp($a['nome'], $b['nome']));

        return $this->success($result);
    }

    /**
     * Listagem unificada de operacoes do PDV
     *
     * Retorna vendas e fechamentos de caixa em uma unica lista paginada,
     * ordenada cronologicamente, com filtros avancados.
     *
     * @authenticated
     * @queryParam store_id integer ID da loja interna. Example: 1
     * @queryParam store_pdv_id integer ID da loja no PDV. Example: 13
     * @queryParam store_alias string Alias da loja PDV. Example: Loja 8
     * @queryParam from string Data inicial (YYYY-MM-DD). Default: hoje-30d. Example: 2026-02-01
     * @queryParam to string Data final (YYYY-MM-DD). Default: hoje. Example: 2026-02-17
     * @queryParam tipo_operacao string Filtrar tipo: `venda` ou `fechamento_caixa`. Example: venda
     * @queryParam status string Filtrar status (ex: `concluido`, `cancelado`, `FECHADO`, `ABERTO`). Example: concluido
     * @queryParam vendedor_id integer Filtrar por vendedor/operador PDV ID. Example: 80
     * @queryParam canal string Canal: `HIPER_CAIXA` ou `HIPER_LOJA`. Example: HIPER_CAIXA
     * @queryParam turno_seq integer Turno sequencial do dia. Example: 1
     * @queryParam meio_pagamento string Nome do meio de pagamento. Example: Pix
     * @queryParam id_finalizador integer ID do finalizador. Example: 5
     * @queryParam min_total numeric Valor minimo. Example: 10
     * @queryParam max_total numeric Valor maximo. Example: 500
     * @queryParam per_page integer Itens por pagina (1-100). Default: 15. Example: 15
     * @queryParam sort string Ordenacao: `asc` ou `desc`. Default: `desc`. Example: desc
     */
    public function operacoes(PdvReportsOperacoesRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $timezone = 'America/Sao_Paulo';

        // --- Resolve Store Scope ---
        $storeId = isset($validated['store_id']) ? (int) $validated['store_id'] : null;
        $storePdvId = isset($validated['store_pdv_id']) ? (int) $validated['store_pdv_id'] : null;
        $storeAlias = isset($validated['store_alias']) ? trim((string) $validated['store_alias']) : null;
        $scope = $this->resolveStoreScope($request, $storeId, $storePdvId, $storeAlias);

        // --- Resolve Date Range ---
        $from = isset($validated['from'])
            ? CarbonImmutable::parse((string) $validated['from'], $timezone)->startOfDay()->setTimezone('UTC')
            : CarbonImmutable::now($timezone)->subDays(30)->startOfDay()->setTimezone('UTC');
        $to = isset($validated['to'])
            ? CarbonImmutable::parse((string) $validated['to'], $timezone)->endOfDay()->setTimezone('UTC')
            : CarbonImmutable::now($timezone)->endOfDay()->setTimezone('UTC');

        if ($to->lt($from)) {
            throw ValidationException::withMessages([
                'to' => ['O campo to deve ser maior ou igual ao campo from.'],
            ]);
        }

        $tipoOperacao = $validated['tipo_operacao'] ?? null;
        $sortDirection = (string) ($validated['sort'] ?? 'desc');
        $perPage = (int) ($validated['per_page'] ?? 15);

        $subQueries = [];

        // ============================================
        // Subquery 1: Vendas (Sales)
        // ============================================
        if ($tipoOperacao === null || $tipoOperacao === 'venda') {
            // Item aggregate for seller + count
            $itemAgg = DB::table('pdv_venda_itens as vi')
                ->select([
                    'vi.store_pdv_id',
                    'vi.canal',
                    'vi.id_operacao',
                    DB::raw('COUNT(*) as itens_count'),
                    DB::raw('MIN(vi.vendedor_pdv_id) as vendedor_pdv_id'),
                    DB::raw('MAX(vi.vendedor_guid) as vendedor_guid'),
                    DB::raw('MAX(vi.vendedor_nome) as vendedor_nome_pdv'),
                ])
                ->groupBy('vi.store_pdv_id', 'vi.canal', 'vi.id_operacao');

            // Dominant payment per sale
            $payDom = DB::table('pdv_venda_pagamentos as vp')
                ->select([
                    'vp.store_pdv_id',
                    'vp.canal',
                    'vp.id_operacao',
                    DB::raw('MAX(vp.meio_pagamento) as meio_pagamento_dominante'),
                ])
                ->groupBy('vp.store_pdv_id', 'vp.canal', 'vp.id_operacao');

            $vendasQuery = DB::table('pdv_vendas as v')
                ->leftJoin('stores as s', 'v.store_id', '=', 's.id')
                ->leftJoinSub($itemAgg, 'it', function ($join): void {
                    $join->on('it.store_pdv_id', '=', 'v.store_pdv_id')
                        ->on('it.canal', '=', 'v.canal')
                        ->on('it.id_operacao', '=', 'v.id_operacao');
                })
                ->leftJoin('users as u_by_guid', 'u_by_guid.guid', '=', 'it.vendedor_guid')
                ->leftJoin('pdv_user_mappings as pum', function ($join): void {
                    $join->on('pum.store_pdv_id', '=', 'v.store_pdv_id')
                        ->on('pum.pdv_user_id', '=', 'it.vendedor_pdv_id');
                })
                ->leftJoin('users as u_map', 'pum.user_id', '=', 'u_map.id')
                ->leftJoinSub($payDom, 'pd', function ($join): void {
                    $join->on('pd.store_pdv_id', '=', 'v.store_pdv_id')
                        ->on('pd.canal', '=', 'v.canal')
                        ->on('pd.id_operacao', '=', 'v.id_operacao');
                })
                ->select([
                    DB::raw("'venda' as tipo_operacao"),
                    'v.data_hora',
                    'v.store_id',
                    's.name as store_name',
                    'v.store_pdv_id',
                    DB::raw('COALESCE(v.turno_seq, 0) as turno_seq'),
                    'v.canal',
                    DB::raw('v.id_operacao as operacao_id'),
                    DB::raw("CONCAT('#', v.id_operacao) as operacao_label"),
                    DB::raw('COALESCE(v.status, \'concluido\') as status'),
                    DB::raw('COALESCE(it.itens_count, 0) as itens'),
                    'v.total as valor',
                    DB::raw('COALESCE(u_by_guid.name, u_map.name, it.vendedor_nome_pdv) as vendedor_nome'),
                    DB::raw('COALESCE(it.vendedor_pdv_id, 0) as vendedor_pdv_id'),
                    DB::raw('pd.meio_pagamento_dominante as meio_pagamento'),
                    DB::raw('NULL as closure_uuid'), // Placeholder for union compatibility
                    'v.id as internal_id',
                ])
                ->whereBetween('v.data_hora', [$from->toDateTimeString(), $to->toDateTimeString()]);

            $this->applyStoreScopeToQuery($vendasQuery, $scope, 'v');

            // Apply venda-specific filters
            if (!empty($validated['canal'])) {
                $vendasQuery->where('v.canal', (string) $validated['canal']);
            }
            if (isset($validated['turno_seq'])) {
                $vendasQuery->where('v.turno_seq', (int) $validated['turno_seq']);
            }
            if (isset($validated['min_total'])) {
                $vendasQuery->where('v.total', '>=', (float) $validated['min_total']);
            }
            if (isset($validated['max_total'])) {
                $vendasQuery->where('v.total', '<=', (float) $validated['max_total']);
            }
            if (!empty($validated['status'])) {
                $statusFilter = (string) $validated['status'];
                // Only apply to vendas if it's not a turno-specific status
                if (!in_array(strtoupper($statusFilter), ['ABERTO', 'FECHADO'], true)) {
                    $vendasQuery->where(DB::raw('COALESCE(v.status, \'concluido\')'), $statusFilter);
                } else {
                    // This status is turno-only; skip vendas entirely
                    $vendasQuery->whereRaw('1 = 0');
                }
            }
            if (isset($validated['vendedor_id'])) {
                $vendedorId = (int) $validated['vendedor_id'];
                $vendasQuery->whereExists(function ($sub) use ($vendedorId): void {
                    $sub->selectRaw('1')
                        ->from('pdv_venda_itens as vif')
                        ->whereColumn('vif.store_pdv_id', 'v.store_pdv_id')
                        ->whereColumn('vif.canal', 'v.canal')
                        ->whereColumn('vif.id_operacao', 'v.id_operacao')
                        ->where('vif.vendedor_pdv_id', $vendedorId);
                });
            }
            if (isset($validated['id_finalizador']) || !empty($validated['meio_pagamento'])) {
                $idFinalizador = isset($validated['id_finalizador']) ? (int) $validated['id_finalizador'] : null;
                $meioPagamento = !empty($validated['meio_pagamento']) ? trim((string) $validated['meio_pagamento']) : null;

                $vendasQuery->whereExists(function ($sub) use ($idFinalizador, $meioPagamento): void {
                    $sub->selectRaw('1')
                        ->from('pdv_venda_pagamentos as vpf')
                        ->whereColumn('vpf.store_pdv_id', 'v.store_pdv_id')
                        ->whereColumn('vpf.canal', 'v.canal')
                        ->whereColumn('vpf.id_operacao', 'v.id_operacao');
                    if ($idFinalizador !== null) {
                        $sub->where('vpf.id_finalizador', $idFinalizador);
                    }
                    if ($meioPagamento !== null) {
                        $sub->where('vpf.meio_pagamento', $meioPagamento);
                    }
                });
            }

            $subQueries[] = $vendasQuery;
        }

        // ============================================
        // Subquery 2: Fechamento de Caixa (Turnos)
        // ============================================
        if ($tipoOperacao === null || $tipoOperacao === 'fechamento_caixa') {
            $turnosQuery = DB::table('pdv_turnos as t')
                ->leftJoin('stores as s2', 't.store_id', '=', 's2.id')
                ->select([
                    DB::raw("'fechamento_caixa' as tipo_operacao"),
                    DB::raw('MAX(COALESCE(t.data_hora_termino, t.data_hora_inicio)) as data_hora'),
                    't.store_id',
                    DB::raw('MAX(s2.name) as store_name'),
                    't.store_pdv_id',
                    DB::raw('COALESCE(t.sequencial, 0) as turno_seq'),
                    DB::raw("'UNIFICADO' as canal"), // Force unified canal
                    DB::raw('0 as operacao_id'),
                    DB::raw("CONCAT('Turno #', COALESCE(t.sequencial, '?')) as operacao_label"),
                    // Status is FECHADO only if all components are closed (using MIN because true=1, false=0)
                    DB::raw("IF(MIN(t.fechado), 'FECHADO', 'ABERTO') as status"),
                    DB::raw('SUM(COALESCE(t.qtd_vendas, 0)) as itens'),
                    DB::raw('SUM(t.total_sistema) as valor'),
                    DB::raw('MAX(COALESCE(t.operador_nome, t.responsavel_nome)) as vendedor_nome'),
                    DB::raw('MAX(COALESCE(t.operador_pdv_id, t.responsavel_pdv_id, 0)) as vendedor_pdv_id'),
                    DB::raw('NULL as meio_pagamento'),
                    DB::raw('MAX(t.closure_uuid) as closure_uuid'), // Expose UUID for details
                    DB::raw('MAX(t.id) as internal_id'), // Use MAX id for paging stability
                ])
                ->where(function ($q) use ($from, $to) {
                    $q->whereBetween(DB::raw('COALESCE(t.data_hora_termino, t.data_hora_inicio)'), [$from->toDateTimeString(), $to->toDateTimeString()]);
                });

            $this->applyStoreScopeToQuery($turnosQuery, $scope, 't');

            // Apply Filters specific to Turnos (aggregated)

            // Note: 'canal' filter is ignored for Unified Closures as they contain all channels.

            if (isset($validated['turno_seq'])) {
                $turnosQuery->where('t.sequencial', (int) $validated['turno_seq']);
            }

            if (isset($validated['vendedor_id'])) {
                $vendedorId = (int) $validated['vendedor_id'];
                $turnosQuery->where(function ($q) use ($vendedorId) {
                    $q->where('t.operador_pdv_id', $vendedorId)
                        ->orWhere('t.responsavel_pdv_id', $vendedorId);
                });
            }

            // Group By Logic to Unify Rows
            $turnosQuery->groupBy([
                't.store_id',
                't.store_pdv_id',
                't.sequencial',
                DB::raw('DATE(t.data_hora_inicio)'),
                // We don't group by 'canal' to merge them.
            ]);

            // Having Clause Filters (Must apply AFTER aggregation)
            if (isset($validated['min_total'])) {
                $turnosQuery->having('valor', '>=', (float) $validated['min_total']);
            }
            if (isset($validated['max_total'])) {
                $turnosQuery->having('valor', '<=', (float) $validated['max_total']);
            }
            if (!empty($validated['status'])) {
                $statusFilter = strtoupper((string) $validated['status']);
                if (in_array($statusFilter, ['ABERTO', 'FECHADO'], true)) {
                    // MIN(fechado) gives 1 if all closed, 0 if any open
                    $operator = $statusFilter === 'FECHADO' ? '=' : '!=';
                    // Actually: IF(MIN(fechado), 'FECHADO', 'ABERTO')
                    // So FECHADO requires MIN(fechado) == 1
                    // ABERTO requires MIN(fechado) == 0
                    $turnosQuery->havingRaw("MIN(t.fechado) $operator 1");
                } else {
                    $turnosQuery->whereRaw('1 = 0');
                }
            }

            // Exclude if filtering by payments (turnos don't have payment info at this level)
            if (isset($validated['id_finalizador']) || !empty($validated['meio_pagamento'])) {
                $turnosQuery->whereRaw('1 = 0');
            }

            $subQueries[] = $turnosQuery;
        }

        // ============================================
        // UNION ALL + Paginate
        // ============================================
        if (empty($subQueries)) {
            return response()->json([
                'data' => [],
                'summary' => ['total_operacoes' => 0, 'total_valor' => 0],
                'filters' => $this->buildOperacoesFilters($scope, $validated, $from, $to, $sortDirection),
                'meta' => $this->meta(),
            ]);
        }

        // Build UNION
        $unionQuery = array_shift($subQueries);
        foreach ($subQueries as $sub) {
            $unionQuery = $unionQuery->unionAll($sub);
        }

        // Wrap in outer query for ORDER + PAGINATE
        $finalQuery = DB::table(DB::raw('(' . $unionQuery->toSql() . ') as operacoes'))
            ->mergeBindings($unionQuery)
            ->select('*');

        // Summary from union
        $summaryQuery = DB::table(DB::raw('(' . $unionQuery->toSql() . ') as op_summary'))
            ->mergeBindings($unionQuery);
        $totalOperacoes = (int) (clone $summaryQuery)->count();
        $totalValor = (float) ((clone $summaryQuery)->sum('valor') ?? 0);
        $totalVendas = (int) (clone $summaryQuery)->where('tipo_operacao', 'venda')->count();
        $totalFechamentos = (int) (clone $summaryQuery)->where('tipo_operacao', 'fechamento_caixa')->count();

        $paginator = $finalQuery
            ->orderBy('data_hora', $sortDirection)
            ->orderBy('internal_id', $sortDirection)
            ->paginate($perPage);

        $rows = collect($paginator->items())->map(fn(object $row): array => [
            'tipo_operacao' => $row->tipo_operacao,
            'data_hora' => $this->toIso8601($row->data_hora),
            'store_id' => $row->store_id !== null ? (int) $row->store_id : null,
            'store_name' => $row->store_name,
            'store_pdv_id' => (int) $row->store_pdv_id,
            'turno_seq' => (int) $row->turno_seq ?: null,
            'canal' => $row->canal ?? 'HIPER_CAIXA',
            'operacao_id' => (int) $row->operacao_id ?: null,
            'operacao_label' => $row->operacao_label,
            'status' => $row->status,
            'itens' => (int) $row->itens,
            'valor' => round((float) $row->valor, 2),
            'vendedor_nome' => $row->vendedor_nome,
            'meio_pagamento' => $row->meio_pagamento,
            'closure_uuid' => $row->closure_uuid, // Expose UUID for closure details
        ])->values()->all();

        return response()->json([
            'data' => $rows,
            'summary' => [
                'total_operacoes' => $totalOperacoes,
                'total_vendas' => $totalVendas,
                'total_fechamentos' => $totalFechamentos,
                'total_valor' => round($totalValor, 2),
            ],
            'filters' => $this->buildOperacoesFilters($scope, $validated, $from, $to, $sortDirection),
            'meta' => $this->meta($paginator),
        ]);
    }

    /**
     * @param array{store_id:int|null,store_pdv_id:int|null,store_alias:string|null} $scope
     */
    private function buildOperacoesFilters(array $scope, array $validated, CarbonImmutable $from, CarbonImmutable $to, string $sortDirection): array
    {
        return [
            'store_id' => $scope['store_id'],
            'store_pdv_id' => $scope['store_pdv_id'],
            'store_alias' => $scope['store_alias'] ?? null,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'tipo_operacao' => $validated['tipo_operacao'] ?? null,
            'status' => $validated['status'] ?? null,
            'vendedor_id' => isset($validated['vendedor_id']) ? (int) $validated['vendedor_id'] : null,
            'canal' => $validated['canal'] ?? null,
            'turno_seq' => isset($validated['turno_seq']) ? (int) $validated['turno_seq'] : null,
            'meio_pagamento' => $validated['meio_pagamento'] ?? null,
            'id_finalizador' => isset($validated['id_finalizador']) ? (int) $validated['id_finalizador'] : null,
            'min_total' => isset($validated['min_total']) ? (float) $validated['min_total'] : null,
            'max_total' => isset($validated['max_total']) ? (float) $validated['max_total'] : null,
            'sort' => $sortDirection,
        ];
    }

    /**
     * Lista de Meios de Pagamento para Filtro
     */
    public function meiosPagamento(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'integer'],
        ]);

        $storeId = (int) $request->input('store_id');

        // Resolver pdv_store_id via tabela de mappings
        $mapping = DB::table('pdv_store_mappings')
            ->where('store_id', $storeId)
            ->where('active', true)
            ->first();

        if (!$mapping) {
            return $this->success([]);
        }
        $storePdvId = (int) $mapping->pdv_store_id;

        $rows = DB::table('pdv_venda_pagamentos')
            ->where('store_pdv_id', $storePdvId)
            ->select('meio_pagamento', 'id_finalizador')
            ->distinct()
            ->get();

        $result = $rows->map(function ($row) {
            return [
                'id' => (string) $row->id_finalizador,
                'nome' => $row->meio_pagamento,
            ];
        })->unique('id')->values()->all();

        return $this->success($result);
    }

    private function meta(?LengthAwarePaginator $paginator = null): array
    {
        $meta = [
            'request_id' => app(AuditContext::class)->getRequestId(),
            'timestamp' => now()->toIso8601String(),
        ];

        if ($paginator !== null) {
            $meta['pagination'] = [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ];
        }

        return $meta;
    }
}
