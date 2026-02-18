<?php

namespace App\Services\Pdv;

use App\Models\PdvTurno;
use App\Models\Store;
use App\Services\Pdv\PdvClosureUnifiedService;
use Illuminate\Support\Facades\DB;

class PdvClosureValidator
{
    /**
     * Validate a single ERP closure item against local pdv_turnos + pdv_turno_pagamentos.
     *
     * Hierarchy:
     *   1. UUID Match  → pdv_turnos.id_turno = Turno.Id
     *   2. Exact Match → store_id + operador_guid + total_sistema
     *   3. Heuristic   → store_id + operador_guid (relaxed total tolerance)
     */
    public function validateClosure(array $erpItem, string $timezone = 'America/Sao_Paulo'): array
    {
        // ── Extract ERP fields ──
        $turnoId = data_get($erpItem, 'Turno.Id');        // UUID do turno
        $lojaId = data_get($erpItem, 'Turno.LojaId');    // UUID da loja
        $usuarioId = data_get($erpItem, 'Turno.UsuarioId'); // UUID do operador
        $sequencial = data_get($erpItem, 'Turno.Sequencial');
        $fechado = data_get($erpItem, 'Turno.Fechado', false);
        $closureUuid = data_get($erpItem, 'Id');              // UUID da operação de fechamento
        $erpTotal = (float) (data_get($erpItem, 'ValorTotalLiquido') ?? 0);
        $cancelada = data_get($erpItem, 'Cancelada', false);

        // ── Cancelled? ──
        if ($cancelada) {
            return [
                'ok' => true,
                'found' => false,
                'reason' => 'Operação de fechamento está CANCELADA no ERP.',
                'status_erp' => 'CANCELLED',
            ];
        }

        // ── Resolve store_id from LojaId (GUID) ──
        $storeId = null;
        $storeName = null;
        $storeCity = null;

        if ($lojaId) {
            $store = Store::where('guid', $lojaId)->first(['id', 'name', 'city']);
            if ($store) {
                $storeId = $store->id;
                $storeName = $store->name;
                $storeCity = $store->city;
            }
        }

        // ══════════════════════════════════════════
        // 1) GOLDEN MATCH — by turno UUID
        // ══════════════════════════════════════════
        if ($turnoId) {
            $turno = PdvTurno::where('id_turno', $turnoId)->first();
            if ($turno) {
                return $this->buildResult($turno, 'uuid', 100, $erpItem, $erpTotal, $storeName, $storeCity);
            }
        }

        // ══════════════════════════════════════════
        // 2) CLOSURE UUID — visão UNIFICADA (prioridade sobre heuristic)
        //    O ERP Id é o closure_uuid que agrupa todos os canais.
        // ══════════════════════════════════════════
        if ($closureUuid) {
            $service = new PdvClosureUnifiedService();
            $unified = $service->getUnifiedByClosureUuid($closureUuid);
            if ($unified) {
                return $this->buildUnifiedResult($unified, 'closure_uuid', 100, $erpItem, $erpTotal, $storeName, $storeCity);
            }

            // Fallback individual (se service não encontrar pdv_closures mas turno tem closure_uuid)
            $turno = PdvTurno::where('closure_uuid', $closureUuid)->first();
            if ($turno) {
                return $this->buildResult($turno, 'closure_uuid_single', 90, $erpItem, $erpTotal, $storeName, $storeCity);
            }
        }

        // ══════════════════════════════════════════
        // 3) EXACT MATCH — store_id + operador_guid + total (sistema ou declarado)
        // ══════════════════════════════════════════
        if ($storeId && $usuarioId) {
            $query = PdvTurno::where('store_id', $storeId)
                ->where('operador_guid', $usuarioId)
                ->where('fechado', true);

            // First try total_declarado (ERP sends declarado value, not raw sistema)
            $turno = (clone $query)
                ->whereBetween('total_declarado', [$erpTotal - 0.05, $erpTotal + 0.05])
                ->orderByDesc('data_hora_inicio')
                ->first();

            if ($turno) {
                return $this->buildResult($turno, 'exact_declarado', 95, $erpItem, $erpTotal, $storeName, $storeCity);
            }

            // Fallback: try total_sistema (tolerance ±0.05)
            $turno = (clone $query)
                ->whereBetween('total_sistema', [$erpTotal - 0.05, $erpTotal + 0.05])
                ->orderByDesc('data_hora_inicio')
                ->first();

            if ($turno) {
                return $this->buildResult($turno, 'exact', 95, $erpItem, $erpTotal, $storeName, $storeCity);
            }

            // ══════════════════════════════════════════
            // 4) HEURISTIC — store_id + operador_guid (relaxed total)
            // ══════════════════════════════════════════
            $turno = (clone $query)
                ->when($sequencial, fn($q) => $q->where('sequencial', $sequencial))
                ->orderByDesc('data_hora_inicio')
                ->first();

            if ($turno) {
                return $this->buildResult($turno, 'heuristic', 75, $erpItem, $erpTotal, $storeName, $storeCity);
            }
        }

        // ── NOT FOUND ──
        return [
            'ok' => true,
            'found' => false,
            'match_type' => null,
            'match_confidence' => 0,
            'reason' => 'Fechamento não encontrado no banco local.',
            'debug' => [
                'turno_id' => $turnoId,
                'loja_id' => $lojaId,
                'usuario_id' => $usuarioId,
                'store_id' => $storeId,
                'erp_total' => $erpTotal,
            ],
        ];
    }

    /**
     * Build the full result with turno data, payments, and comparison.
     */
    private function buildResult(
        PdvTurno $turno,
        string $matchType,
        int $confidence,
        array $erpItem,
        float $erpTotal,
        ?string $storeName,
        ?string $storeCity
    ): array {
        // Load store name from DB if not resolved via LojaId
        if (!$storeName && $turno->store_id) {
            $store = Store::find($turno->store_id, ['name', 'city']);
            if ($store) {
                $storeName = $store->name;
                $storeCity = $store->city;
            }
        }

        // ── Load payments grouped by tipo ──
        $pagamentos = $this->loadPagamentos($turno);

        // ── Build comparison ──
        $comparison = $this->buildComparison($erpItem, $turno, $erpTotal);

        return [
            'ok' => true,
            'found' => true,
            'match_type' => $matchType,
            'match_confidence' => $confidence,
            'turno_db' => [
                'id' => $turno->id,
                'id_turno' => $turno->id_turno,
                'canal' => $turno->canal,
                'sequencial' => $turno->sequencial,
                'fechado' => (bool) $turno->fechado,
                'data_hora_inicio' => $turno->data_hora_inicio?->toIso8601String(),
                'data_hora_termino' => $turno->data_hora_termino?->toIso8601String(),
                'duracao_minutos' => $turno->duracao_minutos,
                'periodo' => $turno->periodo,
                'operador_nome' => $turno->operador_nome,
                'operador_guid' => $turno->operador_guid,
                'operador_hiper_id' => $turno->operador_hiper_id,
                'responsavel_nome' => $turno->responsavel_nome,
                'responsavel_guid' => $turno->responsavel_guid,
                'total_sistema' => (float) $turno->total_sistema,
                'total_declarado' => $turno->total_declarado !== null ? (float) $turno->total_declarado : null,
                'total_falta' => $turno->total_falta !== null ? (float) $turno->total_falta : null,
                'total_sobra' => $turno->total_sobra !== null ? (float) $turno->total_sobra : null,
                'closure_uuid' => $turno->closure_uuid,
                'data_hora_fechamento' => $turno->data_hora_fechamento,
                'tipo_operacao_fechamento' => $turno->tipo_operacao_fechamento,
                'qtd_vendas_sistema' => $turno->qtd_vendas_sistema,
                'store_name' => $storeName,
                'store_city' => $storeCity,
                'last_sync_id' => $turno->last_sync_id,
            ],
            'pagamentos' => $pagamentos,
            'comparison' => $comparison,
        ];
    }

    /**
     * Load payments from pdv_turno_pagamentos grouped by tipo.
     */
    private function loadPagamentos(PdvTurno $turno): array
    {
        $rows = DB::table('pdv_turno_pagamentos')
            ->where('store_pdv_id', $turno->store_pdv_id)
            ->where('canal', $turno->canal)
            ->where('id_turno', $turno->id_turno)
            ->orderBy('tipo')
            ->orderBy('id_finalizador')
            ->get();

        $grouped = [
            'sistema' => [],
            'declarado' => [],
            'falta' => [],
            'sobra' => [],
        ];

        foreach ($rows as $row) {
            $tipo = $row->tipo ?? 'sistema';
            if (!isset($grouped[$tipo])) {
                $grouped[$tipo] = [];
            }

            $grouped[$tipo][] = [
                'id_finalizador' => $row->id_finalizador,
                'meio_pagamento' => $row->meio_pagamento,
                'total' => (float) $row->total,
                'qtd_vendas' => (int) $row->qtd_vendas,
                'pagamento_uuid' => $row->pagamento_uuid ?? null,
                'operacao_uuid' => $row->operacao_uuid ?? null,
            ];
        }

        return $grouped;
    }

    /**
     * Build side-by-side comparison between ERP and DB data.
     */
    private function buildComparison(array $erpItem, PdvTurno $turno, float $erpTotal): array
    {
        $dbTotal = (float) $turno->total_sistema;
        $totalDiff = abs($erpTotal - $dbTotal);

        $erpGuid = strtolower(trim((string) data_get($erpItem, 'Turno.UsuarioId', '')));
        $dbGuid = strtolower(trim((string) ($turno->operador_guid ?? '')));

        $erpSeq = data_get($erpItem, 'Turno.Sequencial');
        $dbSeq = $turno->sequencial;

        $erpFechado = (bool) data_get($erpItem, 'Turno.Fechado', false);
        $dbFechado = (bool) $turno->fechado;

        $erpClosureUuid = strtolower(trim((string) data_get($erpItem, 'Id', '')));
        $dbClosureUuid = strtolower(trim((string) ($turno->closure_uuid ?? '')));

        return [
            'total' => [
                'erp' => $erpTotal,
                'db' => $dbTotal,
                'match' => $totalDiff <= 0.05,
                'diff' => round($totalDiff, 2),
            ],
            'operador' => [
                'erp_guid' => data_get($erpItem, 'Turno.UsuarioId'),
                'db_guid' => $turno->operador_guid,
                'db_nome' => $turno->operador_nome,
                'match' => $erpGuid !== '' && $erpGuid === $dbGuid,
            ],
            'sequencial' => [
                'erp' => $erpSeq,
                'db' => $dbSeq,
                'match' => $erpSeq !== null && (int) $erpSeq === (int) $dbSeq,
            ],
            'fechado' => [
                'erp' => $erpFechado,
                'db' => $dbFechado,
                'match' => $erpFechado === $dbFechado,
            ],
            'closure_uuid' => [
                'erp' => data_get($erpItem, 'Id'),
                'db' => $turno->closure_uuid,
                'match' => $erpClosureUuid !== '' && $erpClosureUuid === $dbClosureUuid,
            ],
            'total_declarado' => [
                'db' => $turno->total_declarado !== null ? (float) $turno->total_declarado : null,
            ],
            'total_falta' => [
                'db' => $turno->total_falta !== null ? (float) $turno->total_falta : null,
            ],
            'total_sobra' => [
                'db' => $turno->total_sobra !== null ? (float) $turno->total_sobra : null,
            ],
        ];
    }

    /**
     * Build result from unified closure data (multiple channels aggregated).
     */
    private function buildUnifiedResult(
        array $unified,
        string $matchType,
        int $confidence,
        array $erpItem,
        float $erpTotal,
        ?string $storeName,
        ?string $storeCity
    ): array {
        // Comparação
        $dbTotal = (float) ($unified['totais']['entries_expected'] ?? 0);
        $totalDiff = abs($erpTotal - $dbTotal);

        $erpGuid = strtolower(trim((string) data_get($erpItem, 'Turno.UsuarioId', '')));
        $dbGuid = strtolower(trim((string) ($unified['operador_guid'] ?? '')));
        $erpSeq = data_get($erpItem, 'Turno.Sequencial');
        $dbSeq = $unified['sequencial'] ?? null;

        // Diff por meio de pagamento: comparar ERP MeiosDePagamentos com local
        $diffPorMeio = [];
        $erpMeios = data_get($erpItem, 'MeiosDePagamentos', []);
        $localSistema = collect($unified['pagamentos']['sistema'] ?? [])->keyBy('meio_pagamento');
        $localDeclarado = collect($unified['pagamentos']['declarado'] ?? [])->keyBy('meio_pagamento');

        foreach ($erpMeios as $meioErp) {
            $nome = $meioErp['Nome'] ?? '?';
            $erpEntradas = (float) ($meioErp['EntradasNoSistema'] ?? 0);
            $erpLancamentos = (float) ($meioErp['LancamentosNoSistema'] ?? 0);
            $erpValorSistema = (float) ($meioErp['ValorNoSistema'] ?? 0);
            $erpFalta = (float) ($meioErp['FaltaDeCaixa'] ?? 0);
            $erpSobra = (float) ($meioErp['SobraDeCaixa'] ?? 0);

            $localSist = $localSistema->get($nome);
            $localDecl = $localDeclarado->get($nome);

            $diffPorMeio[] = [
                'meio' => $nome,
                'erp' => [
                    'entradas_sistema' => $erpEntradas,
                    'lancamentos_sistema' => $erpLancamentos,
                    'valor_sistema' => $erpValorSistema,
                    'falta' => $erpFalta,
                    'sobra' => $erpSobra,
                ],
                'local' => [
                    'sistema' => $localSist ? (float) $localSist['total'] : null,
                    'declarado' => $localDecl ? (float) $localDecl['total'] : null,
                ],
            ];
        }

        return [
            'ok' => true,
            'found' => true,
            'match_type' => $matchType,
            'match_confidence' => $confidence,
            'unified' => true,
            'local_unified' => [
                'closure_uuid' => $unified['closure_uuid'],
                'canal_canonico' => $unified['canal_canonico'],
                'canais_presentes' => $unified['canais_presentes'],
                'sequencial' => $unified['sequencial'],
                'operador_nome' => $unified['operador_nome'],
                'operador_guid' => $unified['operador_guid'],
                'data_hora_inicio' => $unified['data_hora_inicio'],
                'data_hora_termino' => $unified['data_hora_termino'],
                'periodo' => $unified['periodo'],
                'store_name' => $storeName,
                'store_city' => $storeCity,
                'totais' => $unified['totais'],
            ],
            'pagamentos' => $unified['pagamentos'],
            'diff_por_meio' => $diffPorMeio,
            'comparison' => [
                'total' => [
                    'erp' => $erpTotal,
                    'db_unificado' => $dbTotal,
                    'match' => $totalDiff <= 0.05,
                    'diff' => round($totalDiff, 2),
                ],
                'operador' => [
                    'erp_guid' => data_get($erpItem, 'Turno.UsuarioId'),
                    'db_guid' => $unified['operador_guid'],
                    'db_nome' => $unified['operador_nome'],
                    'match' => $erpGuid !== '' && $erpGuid === $dbGuid,
                ],
                'sequencial' => [
                    'erp' => $erpSeq,
                    'db' => $dbSeq,
                    'match' => $erpSeq !== null && (int) $erpSeq === (int) $dbSeq,
                ],
                'closure_uuid' => [
                    'erp' => data_get($erpItem, 'Id'),
                    'db' => $unified['closure_uuid'],
                    'match' => true,
                ],
                'declared_consistent' => $unified['totais']['declared_consistent'],
            ],
        ];
    }
}
