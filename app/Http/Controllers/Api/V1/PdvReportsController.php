<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pdv\PdvReportsRankingVendedorLojaRequest;
use App\Http\Requests\Pdv\PdvReportsRankingVendedoresRequest;
use App\Http\Requests\Pdv\PdvReportsTurnosRequest;
use App\Http\Requests\Pdv\PdvReportsVendaDetalheRequest;
use App\Http\Requests\Pdv\PdvReportsVendasRequest;
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

        $from = isset($validated['from'])
            ? CarbonImmutable::parse((string) $validated['from'])->startOfDay()
            : CarbonImmutable::now()->subDays(30)->startOfDay();
        $to = isset($validated['to'])
            ? CarbonImmutable::parse((string) $validated['to'])->endOfDay()
            : CarbonImmutable::now()->endOfDay();

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
            ->leftJoinSub($itemAgg, 'it', function ($join): void {
                $join->on('it.store_pdv_id', '=', 'v.store_pdv_id')
                    ->on('it.canal', '=', 'v.canal')
                    ->on('it.id_operacao', '=', 'v.id_operacao');
            })
            ->leftJoin('pdv_user_mappings as pum', function ($join): void {
                $join->on('pum.store_pdv_id', '=', 'v.store_pdv_id')
                    ->on('pum.pdv_user_id', '=', 'it.vendedor_pdv_id');
            })
            ->leftJoin('users as u', 'pum.user_id', '=', 'u.id')
            ->leftJoinSub($paymentAgg, 'pg', function ($join): void {
                $join->on('pg.store_pdv_id', '=', 'v.store_pdv_id')
                    ->on('pg.canal', '=', 'v.canal')
                    ->on('pg.id_operacao', '=', 'v.id_operacao');
            })
            ->select([
                'v.id',
                'v.store_id',
                's.name as store_name',
                'v.store_pdv_id',
                'v.id_operacao',
                'v.canal',
                'v.id_turno',
                'v.data_hora',
                'v.total',
                'u.name as seller_name',
                DB::raw('COALESCE(it.itens_count, 0) as itens_count'),
                DB::raw('COALESCE(it.itens_qtd_total, 0) as itens_qtd_total'),
                DB::raw('COALESCE(it.itens_valor_total, 0) as itens_valor_total'),
                DB::raw('COALESCE(pg.pagamentos_count, 0) as pagamentos_count'),
                DB::raw('COALESCE(pg.pagamentos_valor_total, 0) as pagamentos_valor_total'),
            ])
            ->whereBetween('v.data_hora', [$from->toDateTimeString(), $to->toDateTimeString()]);

        $this->applyStoreScopeToQuery($query, $scope, 'v');

        if (!empty($validated['canal'])) {
            $query->where('v.canal', (string) $validated['canal']);
        }
        if (!empty($validated['id_turno'])) {
            $query->where('v.id_turno', (string) $validated['id_turno']);
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
                'store_pdv_id' => (int) $row->store_pdv_id,
                'seller_name' => $row->seller_name ?? null,
                'id_operacao' => (int) $row->id_operacao,
                'canal' => (string) ($row->canal ?? 'HIPER_CAIXA'),
                'id_turno' => $row->id_turno,
                'data_hora' => $this->toIso8601($row->data_hora),
                'total' => (float) $row->total,
                'itens' => [
                    'qtd_linhas' => (int) $row->itens_count,
                    'qtd_total' => (float) $row->itens_qtd_total,
                    'valor_total' => (float) $row->itens_valor_total,
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
            ->select([
                'v.store_id',
                'v.store_pdv_id',
                'v.canal',
                'v.id_operacao',
                'v.id_turno',
                'v.data_hora',
                'v.total',
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
            ])
            ->where('vi.store_pdv_id', $resolvedStorePdvId)
            ->where('vi.canal', $resolvedCanal)
            ->where('vi.id_operacao', $resolvedIdOperacao)
            ->orderBy('vi.line_no')
            ->orderBy('vi.id')
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
            'vendedor_pdv_id' => $row->vendedor_pdv_id !== null ? (int) $row->vendedor_pdv_id : null,
            'vendedor_nome' => $row->vendedor_nome,
            'vendedor_login' => $row->vendedor_login,
            'vendedor_user_id' => $row->vendedor_user_id !== null ? (int) $row->vendedor_user_id : null,
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
                'store_pdv_id' => $resolvedStorePdvId,
                'canal' => $resolvedCanal,
                'id_operacao' => $resolvedIdOperacao,
                'id_turno' => $venda->id_turno,
                'data_hora' => $this->toIso8601($venda->data_hora),
                'total' => (float) $venda->total,
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
            ->leftJoin('pdv_usuarios as pu', 'pu.id_usuario_hiper', '=', 'vi.vendedor_pdv_id')
            ->selectRaw('vi.vendedor_pdv_id as vendedor_id')
            ->selectRaw('MAX(COALESCE(pu.nome_padronizado, pu.nome_hiper, vi.vendedor_nome)) as vendedor_nome')
            ->selectRaw('COUNT(DISTINCT v.id) as qtd_vendas')
            ->selectRaw('COALESCE(SUM(vi.total), 0) as total_vendido')
            ->selectRaw('COALESCE(SUM(vi.qtd), 0) as total_itens')
            ->whereNotNull('vi.vendedor_pdv_id')
            ->whereBetween('v.data_hora', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->groupBy('vi.vendedor_pdv_id');

        $this->applyStoreScopeToQuery($query, $scope, 'v');

        if (!empty($validated['canal'])) {
            $query->where('v.canal', (string) $validated['canal']);
        }

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
                'vendedores' => $ranking->count(),
                'total_vendido' => round((float) $ranking->sum('total_vendido'), 2),
                'qtd_vendas' => (int) $ranking->sum('qtd_vendas'),
                'total_itens' => round((float) $ranking->sum('total_itens'), 3),
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
    public function vendedores(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'integer'],
        ]);

        $storeId = (int) $request->input('store_id');

        $store = \App\Models\Store::find($storeId);
        if (!$store || !$store->pdv_store_id) {
            return $this->success([]);
        }
        $storePdvId = (int) $store->pdv_store_id;

        // 1. Buscar Mappings ativos para esta loja
        $mappings = DB::table('pdv_user_mappings')
            ->where('store_pdv_id', $storePdvId)
            ->where('active', true)
            ->get()
            ->keyBy('pdv_user_id');

        // 2. Buscar IDs de vendedores usados em vendas (histórico)
        $sellerIds = DB::table('pdv_venda_itens')
            ->where('store_pdv_id', $storePdvId)
            ->select('vendedor_pdv_id')
            ->distinct()
            ->pluck('vendedor_pdv_id');

        // 3. Buscar dados crus em pdv_usuarios para enriquecer nomes (fallback)
        $pdvUsers = DB::table('pdv_usuarios')
            ->whereIn('id_usuario_hiper', $sellerIds)
            ->get()
            ->keyBy('id_usuario_hiper');

        $result = [];
        foreach ($sellerIds as $id) {
            $id = (int) $id;
            $mapping = $mappings[$id] ?? null;
            $pdvUser = $pdvUsers[$id] ?? null;

            // Nome: Mapping > PdvUser > Fallback
            if ($mapping && $mapping->pdv_user_name) {
                $nome = $mapping->pdv_user_name;
            } elseif ($pdvUser) {
                $nome = $pdvUser->nome_hiper ?? $pdvUser->nome_padronizado;
            } else {
                $nome = "Vendedor #{$id}";
            }

            // Capitalizar nome para ficar bonito (Ex: JOAO DA SILVA -> Joao Da Silva)
            $nome = \Illuminate\Support\Str::title(\Illuminate\Support\Str::lower($nome));

            $result[] = [
                'id' => (string) $id,
                'nome' => $nome,
                'user_id' => $mapping?->user_id,
                'source' => $mapping ? 'mapped' : ($pdvUser ? 'pdv_registry' : 'fallback'),
            ];
        }

        // Ordenar por nome
        usort($result, fn($a, $b) => strcasecmp($a['nome'], $b['nome']));

        return $this->success($result);
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
        $store = \App\Models\Store::find($storeId);
        if (!$store || !$store->pdv_store_id) {
            return $this->success([]);
        }
        $storePdvId = (int) $store->pdv_store_id;

        $rows = DB::table('pdv_turno_pagamentos')
            ->where('store_pdv_id', $storePdvId)
            ->select('tipo', 'meio_pagamento', 'id_finalizador')
            ->distinct()
            ->get();

        $result = $rows->map(function ($row) {
            $label = $row->meio_pagamento;
            $tipo = $row->tipo;

            // Se tipo for diferente e relevante, adicionar
            if ($tipo && $tipo !== $label && $tipo !== 'Não Definido') {
                $label .= " ({$tipo})";
            }

            return [
                'id' => (string) $row->id_finalizador, // Usar ID do finalizador como chave se possível
                'nome' => $label,
                'tipo' => $tipo,
                'meio_pagamento' => $row->meio_pagamento,
            ];
        })->unique('nome')->values()->all();

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
