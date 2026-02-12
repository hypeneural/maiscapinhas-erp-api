<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Support\Audit\AuditContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PdvReportsController extends Controller
{
    use ApiResponse;

    public function turnos(Request $request): JsonResponse
    {
        $validated = $request->validate(
            [
                'store_id' => ['nullable', 'integer', 'exists:stores,id'],
                'store_pdv_id' => ['nullable', 'integer', 'min:1'],
                'date' => ['required', 'date'],
                'sequencial' => ['nullable', 'integer', 'min:1'],
                'periodo' => ['nullable', 'string', 'in:MATUTINO,VESPERTINO,NOTURNO'],
                'fechado' => ['nullable', 'boolean'],
                'operador_id' => ['nullable', 'integer', 'min:1'],
                'responsavel_id' => ['nullable', 'integer', 'min:1'],
            ],
            [
                'store_id.integer' => 'O campo store_id deve ser numerico.',
                'store_id.exists' => 'A loja informada em store_id nao foi encontrada.',
                'store_pdv_id.integer' => 'O campo store_pdv_id deve ser numerico.',
                'store_pdv_id.min' => 'O campo store_pdv_id deve ser maior que zero.',
                'date.required' => 'O campo date e obrigatorio.',
                'date.date' => 'O campo date deve estar no formato de data valido (YYYY-MM-DD).',
                'sequencial.integer' => 'O campo sequencial deve ser numerico.',
                'sequencial.min' => 'O campo sequencial deve ser maior que zero.',
                'periodo.in' => 'O campo periodo deve ser MATUTINO, VESPERTINO ou NOTURNO.',
                'fechado.boolean' => 'O campo fechado deve ser true/false (ou 1/0).',
                'operador_id.integer' => 'O campo operador_id deve ser numerico.',
                'operador_id.min' => 'O campo operador_id deve ser maior que zero.',
                'responsavel_id.integer' => 'O campo responsavel_id deve ser numerico.',
                'responsavel_id.min' => 'O campo responsavel_id deve ser maior que zero.',
            ]
        );

        $storeId = isset($validated['store_id']) ? (int) $validated['store_id'] : null;
        $storePdvId = isset($validated['store_pdv_id']) ? (int) $validated['store_pdv_id'] : null;
        if ($storeId === null && $storePdvId === null) {
            throw ValidationException::withMessages([
                'store' => ['Informe store_id ou store_pdv_id.'],
            ]);
        }

        $scope = $this->resolveStoreScope($request, $storeId, $storePdvId);
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
                'date' => $date,
                'sequencial' => isset($validated['sequencial']) ? (int) $validated['sequencial'] : null,
                'periodo' => $validated['periodo'] ?? null,
                'fechado' => array_key_exists('fechado', $validated) ? (bool) $validated['fechado'] : null,
                'operador_id' => isset($validated['operador_id']) ? (int) $validated['operador_id'] : null,
                'responsavel_id' => isset($validated['responsavel_id']) ? (int) $validated['responsavel_id'] : null,
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
                    static fn (array $row): float => abs((float) data_get($row, 'totais.total_falta', 0))
                ), 2),
            ],
            'turnos' => $rows->all(),
        ]);
    }

    public function vendas(Request $request): JsonResponse
    {
        $validated = $request->validate(
            [
                'store_id' => ['nullable', 'integer', 'exists:stores,id'],
                'store_pdv_id' => ['nullable', 'integer', 'min:1'],
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date', 'after_or_equal:from'],
                'vendedor_id' => ['nullable', 'integer', 'min:1'],
                'canal' => ['nullable', 'string', 'in:HIPER_CAIXA,HIPER_LOJA'],
                'id_turno' => ['nullable', 'string', 'max:64'],
                'id_finalizador' => ['nullable', 'integer', 'min:1'],
                'meio_pagamento' => ['nullable', 'string', 'max:120'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
                'sort' => ['nullable', 'string', 'in:asc,desc'],
            ],
            [
                'store_id.integer' => 'O campo store_id deve ser numerico.',
                'store_id.exists' => 'A loja informada em store_id nao foi encontrada.',
                'store_pdv_id.integer' => 'O campo store_pdv_id deve ser numerico.',
                'store_pdv_id.min' => 'O campo store_pdv_id deve ser maior que zero.',
                'from.date' => 'O campo from deve ser uma data valida.',
                'to.date' => 'O campo to deve ser uma data valida.',
                'to.after_or_equal' => 'O campo to deve ser maior ou igual ao campo from.',
                'vendedor_id.integer' => 'O campo vendedor_id deve ser numerico.',
                'vendedor_id.min' => 'O campo vendedor_id deve ser maior que zero.',
                'canal.in' => 'O campo canal deve ser HIPER_CAIXA ou HIPER_LOJA.',
                'id_turno.max' => 'O campo id_turno excede o tamanho maximo permitido.',
                'id_finalizador.integer' => 'O campo id_finalizador deve ser numerico.',
                'id_finalizador.min' => 'O campo id_finalizador deve ser maior que zero.',
                'meio_pagamento.max' => 'O campo meio_pagamento excede o tamanho maximo permitido.',
                'per_page.integer' => 'O campo per_page deve ser numerico.',
                'per_page.min' => 'O campo per_page deve ser maior que zero.',
                'per_page.max' => 'O campo per_page nao pode ser maior que 100.',
                'sort.in' => 'O campo sort deve ser asc ou desc.',
            ]
        );

        $storeId = isset($validated['store_id']) ? (int) $validated['store_id'] : null;
        $storePdvId = isset($validated['store_pdv_id']) ? (int) $validated['store_pdv_id'] : null;
        $scope = $this->resolveStoreScope($request, $storeId, $storePdvId);

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
            ->leftJoinSub($itemAgg, 'it', function ($join): void {
                $join->on('it.store_pdv_id', '=', 'v.store_pdv_id')
                    ->on('it.canal', '=', 'v.canal')
                    ->on('it.id_operacao', '=', 'v.id_operacao');
            })
            ->leftJoinSub($paymentAgg, 'pg', function ($join): void {
                $join->on('pg.store_pdv_id', '=', 'v.store_pdv_id')
                    ->on('pg.canal', '=', 'v.canal')
                    ->on('pg.id_operacao', '=', 'v.id_operacao');
            })
            ->select([
                'v.id',
                'v.store_id',
                'v.store_pdv_id',
                'v.id_operacao',
                'v.canal',
                'v.id_turno',
                'v.data_hora',
                'v.total',
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
            ->map(fn ($row): array => [
                'store_id' => $row->store_id !== null ? (int) $row->store_id : null,
                'store_pdv_id' => (int) $row->store_pdv_id,
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

    public function rankingVendedores(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['nullable', 'string', 'in:daily,weekly,monthly'],
            'reference_date' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'store_pdv_id' => ['nullable', 'integer', 'min:1'],
            'canal' => ['nullable', 'string', 'in:HIPER_CAIXA,HIPER_LOJA'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $storeId = isset($validated['store_id']) ? (int) $validated['store_id'] : null;
        $storePdvId = isset($validated['store_pdv_id']) ? (int) $validated['store_pdv_id'] : null;
        $scope = $this->resolveStoreScope($request, $storeId, $storePdvId);

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

    public function rankingVendedorLoja(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'store_pdv_id' => ['nullable', 'integer', 'min:1'],
            'vendedor_id' => ['nullable', 'integer', 'min:1'],
            'canal' => ['nullable', 'string', 'in:HIPER_CAIXA,HIPER_LOJA'],
            'sort_by' => ['nullable', 'string', 'in:total_vendido,qtd_vendas,total_itens'],
            'sort' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $storeId = isset($validated['store_id']) ? (int) $validated['store_id'] : null;
        $storePdvId = isset($validated['store_pdv_id']) ? (int) $validated['store_pdv_id'] : null;
        $scope = $this->resolveStoreScope($request, $storeId, $storePdvId);

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
            ->leftJoin('pdv_store_mappings as psm', function ($join): void {
                $join->on('psm.pdv_store_id', '=', 'v.store_pdv_id')
                    ->where('psm.active', true);
            })
            ->leftJoin('stores as s', 's.id', '=', 'psm.store_id')
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
     * @return array{store_id:int|null,store_pdv_id:int|null,allowed_store_ids:array<int, int>|null}
     */
    private function resolveStoreScope(Request $request, ?int $storeId, ?int $storePdvId): array
    {
        $user = $request->user();
        if (!$user) {
            abort(401, 'Unauthenticated.');
        }

        $allowedStoreIds = null;
        if (!$user->isSuperAdmin()) {
            $allowedStoreIds = $user->storeUsers()
                ->pluck('store_id')
                ->map(static fn (mixed $value): int => (int) $value)
                ->filter(static fn (int $value): bool => $value > 0)
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

        if ($storePdvId !== null) {
            $mappedStoreId = DB::table('pdv_store_mappings')
                ->where('pdv_store_id', $storePdvId)
                ->where('active', true)
                ->value('store_id');
            $mappedStoreId = $mappedStoreId !== null ? (int) $mappedStoreId : null;

            if ($storeId !== null && $mappedStoreId !== null && $storeId !== $mappedStoreId) {
                throw ValidationException::withMessages([
                    'store' => ['store_id e store_pdv_id nao pertencem a mesma loja.'],
                ]);
            }

            if ($storeId === null && $mappedStoreId !== null) {
                $storeId = $mappedStoreId;
            }

            if (!$user->isSuperAdmin() && ($mappedStoreId === null || !$user->hasAccessToStore($mappedStoreId))) {
                abort(403, 'Voce nao tem acesso a esta loja PDV.');
            }
        }

        return [
            'store_id' => $storeId,
            'store_pdv_id' => $storePdvId,
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

        if ($scope['store_id'] === null
            && $scope['store_pdv_id'] === null
            && is_array($scope['allowed_store_ids'])) {
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
            ->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->unique()
            ->values()
            ->all();
        if ($turnoIds === []) {
            return [];
        }

        $storePdvIds = $turnos->pluck('store_pdv_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
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
            ->groupBy(fn ($row): string => $this->turnoCompositeKey((int) $row->store_pdv_id, (string) $row->id_turno))
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
