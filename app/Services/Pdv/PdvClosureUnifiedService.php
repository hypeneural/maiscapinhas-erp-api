<?php

declare(strict_types=1);

namespace App\Services\Pdv;

use App\Models\PdvCashRule;
use App\Models\PdvClosure;
use App\Models\PdvClosurePagamento;
use App\Models\PdvTurno;
use App\Models\PdvTurnoPagamento;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Serviço canônico para unificação de fechamentos de caixa.
 *
 * Regras imutáveis:
 *   SISTEMA (op=1)         → Somar ambos canais (HIPER_CAIXA + HIPER_LOJA)
 *   DECLARADO (op=9)       → 1x só, canal canônico (CAIXA > LOJA)
 *   FALTA (op=4)           → 1x só, canal canônico
 *   SOBRA (op=3/5)         → 1x só, canal canônico
 *
 * Canal canônico: HIPER_CAIXA se existir, senão HIPER_LOJA.
 */
class PdvClosureUnifiedService
{
    /** Tolerância para comparação de valores declarados entre canais. */
    private const CONSISTENCY_TOLERANCE = 0.01;

    /**
     * Retorna visão unificada de um fechamento pelo closure_uuid.
     *
     * @return array|null  null se não encontrar turnos com esse closure_uuid
     */
    public function getUnifiedByClosureUuid(string $closureUuid): ?array
    {
        $turnos = PdvTurno::where('closure_uuid', $closureUuid)->get();

        if ($turnos->isEmpty()) {
            return null;
        }

        return $this->buildUnified($turnos);
    }

    /**
     * Lista fechamentos unificados para uma loja em um período.
     *
     * @return Collection<int, array>
     */
    public function listUnifiedByStoreDateRange(
        int $storePdvId,
        string $dateFrom,
        string $dateTo
    ): Collection {
        // Busca closure_uuids distintos no range
        $closureUuids = PdvTurno::where('store_pdv_id', $storePdvId)
            ->whereNotNull('closure_uuid')
            ->where('fechado', true)
            ->whereDate('data_hora_inicio', '>=', $dateFrom)
            ->whereDate('data_hora_inicio', '<=', $dateTo)
            ->select('closure_uuid')
            ->distinct()
            ->pluck('closure_uuid');

        if ($closureUuids->isEmpty()) {
            return collect();
        }

        // Carrega todos os turnos de uma vez (evita N+1)
        $allTurnos = PdvTurno::whereIn('closure_uuid', $closureUuids)->get();

        return $closureUuids->map(function (string $uuid) use ($allTurnos) {
            $turnosForUuid = $allTurnos->where('closure_uuid', $uuid);
            return $this->buildUnified($turnosForUuid);
        })->sortByDesc('data_hora_inicio')->values();
    }

    /**
     * Lista fechamentos unificados por store_id + período + data.
     *
     * @return Collection<int, array>
     */
    public function listUnifiedByStoreIdDatePeriod(
        int $storeId,
        string $date,
        array $periodos
    ): Collection {
        $closureUuids = PdvTurno::where('store_id', $storeId)
            ->whereNotNull('closure_uuid')
            ->where('fechado', true)
            ->whereDate('data_hora_inicio', $date)
            ->whereIn('periodo', $periodos)
            ->select('closure_uuid')
            ->distinct()
            ->pluck('closure_uuid');

        if ($closureUuids->isEmpty()) {
            return collect();
        }

        $allTurnos = PdvTurno::whereIn('closure_uuid', $closureUuids)->get();

        return $closureUuids->map(function (string $uuid) use ($allTurnos) {
            $turnosForUuid = $allTurnos->where('closure_uuid', $uuid);
            return $this->buildUnified($turnosForUuid);
        })->sortByDesc('data_hora_inicio')->values();
    }

    /**
     * Valida consistência dos dados entre canais para um closure_uuid.
     *
     * Checa se os valores declarados/falta/sobra são iguais nos 2 canais.
     */
    public function validateConsistency(string $closureUuid): array
    {
        $turnos = PdvTurno::where('closure_uuid', $closureUuid)->get();

        if ($turnos->isEmpty()) {
            return ['consistent' => false, 'issues' => ['Closure não encontrado']];
        }

        if ($turnos->count() < 2) {
            return ['consistent' => true, 'issues' => [], 'note' => 'Apenas 1 canal presente'];
        }

        $issues = [];

        // Comparar total_declarado
        $declarados = $turnos->pluck('total_declarado')->filter()->values();
        if ($declarados->count() >= 2) {
            $min = (float) $declarados->min();
            $max = (float) $declarados->max();
            if (abs($max - $min) > self::CONSISTENCY_TOLERANCE) {
                $issues[] = sprintf(
                    'total_declarado diverge: min=%.2f max=%.2f diff=%.2f',
                    $min,
                    $max,
                    abs($max - $min)
                );
            }
        }

        // Comparar total_falta
        $faltas = $turnos->pluck('total_falta')->filter()->values();
        if ($faltas->count() >= 2) {
            $min = (float) $faltas->min();
            $max = (float) $faltas->max();
            if (abs($max - $min) > self::CONSISTENCY_TOLERANCE) {
                $issues[] = sprintf(
                    'total_falta diverge: min=%.2f max=%.2f',
                    $min,
                    $max
                );
            }
        }

        // Comparar total_sobra
        $sobras = $turnos->pluck('total_sobra')->filter()->values();
        if ($sobras->count() >= 2) {
            $min = (float) $sobras->min();
            $max = (float) $sobras->max();
            if (abs($max - $min) > self::CONSISTENCY_TOLERANCE) {
                $issues[] = sprintf(
                    'total_sobra diverge: min=%.2f max=%.2f',
                    $min,
                    $max
                );
            }
        }

        // Comparar pagamentos declarados por id_finalizador
        $canais = $turnos->pluck('canal')->unique()->values();
        if ($canais->count() >= 2) {
            $paymentsByCanal = [];
            foreach ($canais as $canal) {
                $turno = $turnos->firstWhere('canal', $canal);
                $payments = PdvTurnoPagamento::where([
                    'store_pdv_id' => $turno->store_pdv_id,
                    'canal' => $canal,
                    'id_turno' => $turno->id_turno,
                    'tipo' => 'declarado',
                ])->get();
                $paymentsByCanal[$canal] = $payments->keyBy('id_finalizador')
                    ->map(fn($p) => (float) $p->total);
            }

            // Comparar se ambos canais têm mesmos finalizadores e valores
            $canalKeys = array_keys($paymentsByCanal);
            if (count($canalKeys) >= 2) {
                $a = $paymentsByCanal[$canalKeys[0]];
                $b = $paymentsByCanal[$canalKeys[1]];
                $allIds = $a->keys()->merge($b->keys())->unique();

                foreach ($allIds as $finId) {
                    $va = $a->get($finId, 0.0);
                    $vb = $b->get($finId, 0.0);
                    if (abs($va - $vb) > self::CONSISTENCY_TOLERANCE) {
                        $issues[] = sprintf(
                            'declarado id_finalizador=%d diverge: %s=%.2f vs %s=%.2f',
                            $finId,
                            $canalKeys[0],
                            $va,
                            $canalKeys[1],
                            $vb
                        );
                    }
                }
            }
        }

        return [
            'consistent' => empty($issues),
            'issues' => $issues,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // Private: construção da visão unificada
    // ──────────────────────────────────────────────────────────────

    /**
     * Constrói a visão unificada a partir de uma coleção de turnos (mesmo closure_uuid).
     */
    private function buildUnified(Collection $turnos): array
    {
        $closureUuid = $turnos->first()->closure_uuid;
        $canais = $turnos->pluck('canal')->unique()->values()->toArray();

        // Canal canônico: HIPER_CAIXA > HIPER_LOJA
        $canalCanonico = in_array('HIPER_CAIXA', $canais) ? 'HIPER_CAIXA' : $canais[0];
        $canonTurno = $turnos->firstWhere('canal', $canalCanonico);

        // ── SISTEMA (op=1): somar ambos canais ──
        $pagamentosSistema = $this->getPaymentsByClosureTurnos($turnos, 'sistema');

        // ── DECLARADO/FALTA/SOBRA: apenas canal canônico ──
        $pagamentosDeclarado = $this->getCanonicalPayments($canonTurno, 'declarado');
        $pagamentosFalta = $this->getCanonicalPayments($canonTurno, 'falta');
        $pagamentosSobra = $this->getCanonicalPayments($canonTurno, 'sobra');

        // ── Totais ──
        $sistemaCaixa = (float) $turnos->where('canal', 'HIPER_CAIXA')->sum('total_sistema');
        $sistemaLoja = (float) $turnos->where('canal', 'HIPER_LOJA')->sum('total_sistema');

        $declMin = (float) ($turnos->whereNotNull('total_declarado')->min('total_declarado') ?? 0);
        $declMax = (float) ($turnos->whereNotNull('total_declarado')->max('total_declarado') ?? 0);
        $declConsistent = $turnos->count() < 2 || abs($declMax - $declMin) <= self::CONSISTENCY_TOLERANCE;

        return [
            'closure_uuid' => $closureUuid,
            'store_pdv_id' => $canonTurno->store_pdv_id,
            'store_id' => $canonTurno->store_id,
            'sequencial' => $turnos->max('sequencial'),
            'operador_guid' => $turnos->first(fn($t) => $t->operador_guid)?->operador_guid,
            'operador_nome' => $turnos->first(fn($t) => $t->operador_nome)?->operador_nome,
            'operador_login' => $turnos->first(fn($t) => $t->operador_login)?->operador_login,
            'data_hora_inicio' => $turnos->min('data_hora_inicio')?->toDateTimeString(),
            'data_hora_termino' => $turnos->max('data_hora_termino')?->toDateTimeString(),
            'data_hora_fechamento' => $canonTurno->data_hora_fechamento?->toDateTimeString(),
            'periodo' => $canonTurno->periodo,
            'canal_canonico' => $canalCanonico,
            'canais_presentes' => $canais,
            'num_canais' => count($canais),
            'totais' => [
                'sistema_caixa' => $sistemaCaixa,
                'sistema_loja' => $sistemaLoja,
                'sistema_unificado' => $sistemaCaixa + $sistemaLoja,
                'declarado' => (float) ($canonTurno->total_declarado ?? $declMax),
                'falta' => (float) ($canonTurno->total_falta ?? 0),
                'sobra' => (float) ($canonTurno->total_sobra ?? 0),
                'has_loja_sales' => $sistemaLoja > 0,
                'declared_consistent' => $declConsistent,
                'declared_min' => $declMin,
                'declared_max' => $declMax,
            ],
            'pagamentos' => [
                'sistema' => $pagamentosSistema,
                'declarado' => $pagamentosDeclarado,
                'falta' => $pagamentosFalta,
                'sobra' => $pagamentosSobra,
            ],
        ];
    }

    /**
     * Agrega pagamentos de TODOS os canais para um tipo (ex: sistema).
     * Agrupa por id_finalizador e soma totais.
     */
    private function getPaymentsByClosureTurnos(Collection $turnos, string $tipo): array
    {
        // Construir condições para pegar pagamentos de todos os turnos deste closure
        $conditions = $turnos->map(fn($t) => [
            'store_pdv_id' => $t->store_pdv_id,
            'canal' => $t->canal,
            'id_turno' => $t->id_turno,
        ])->values()->toArray();

        if (empty($conditions)) {
            return [];
        }

        // Usar OR para cada turno-canal
        $query = PdvTurnoPagamento::where('tipo', $tipo);
        $query->where(function ($q) use ($conditions) {
            foreach ($conditions as $cond) {
                $q->orWhere(function ($sub) use ($cond) {
                    $sub->where('store_pdv_id', $cond['store_pdv_id'])
                        ->where('canal', $cond['canal'])
                        ->where('id_turno', $cond['id_turno']);
                });
            }
        });

        $payments = $query->get();

        // Agrupar por id_finalizador e somar
        return $payments->groupBy('id_finalizador')->map(function ($group) {
            $first = $group->first();
            return [
                'id_finalizador' => $first->id_finalizador,
                'meio_pagamento' => $first->meio_pagamento,
                'total' => (float) $group->sum('total'),
                'qtd_vendas' => (int) $group->sum('qtd_vendas'),
            ];
        })->values()->toArray();
    }

    /**
     * Retorna pagamentos de um tipo para apenas o turno do canal canônico.
     */
    private function getCanonicalPayments(PdvTurno $canonTurno, string $tipo): array
    {
        return PdvTurnoPagamento::where([
            'store_pdv_id' => $canonTurno->store_pdv_id,
            'canal' => $canonTurno->canal,
            'id_turno' => $canonTurno->id_turno,
            'tipo' => $tipo,
        ])->get()->map(fn($p) => [
                'id_finalizador' => $p->id_finalizador,
                'meio_pagamento' => $p->meio_pagamento,
                'total' => (float) $p->total,
                'qtd_vendas' => (int) $p->qtd_vendas,
            ])->toArray();
    }

    /**
     * Upsert pdv_closures + pdv_closure_pagamentos from turnos with the given closure_uuid.
     *
     * Call this AFTER saving pdv_turnos and pdv_turno_pagamentos in ProcessPdvSyncJob.
     *
     * @return PdvClosure|null  null if no turnos found
     */
    public function upsertClosureFromTurnos(string $closureUuid, ?int $lastSyncId = null): ?PdvClosure
    {
        $unified = $this->getUnifiedByClosureUuid($closureUuid);
        if (!$unified) {
            return null;
        }

        $sistemaCaixa = $unified['totais']['sistema_caixa'];
        $sistemaLoja = $unified['totais']['sistema_loja'];
        $hasLojaSales = $sistemaLoja > 0;

        // Determine total_cash_recomendado based on store rules
        $cashRecommended = $this->getCashRecommended(
            $unified['store_pdv_id'],
            $sistemaCaixa,
            $sistemaLoja
        );

        $closure = PdvClosure::updateOrCreate(
            ['closure_uuid' => $closureUuid],
            [
                'store_pdv_id' => $unified['store_pdv_id'],
                'store_id' => $unified['store_id'],
                'sequencial' => $unified['sequencial'],
                'periodo' => $unified['periodo'],
                'operador_nome' => $unified['operador_nome'],
                'operador_guid' => $unified['operador_guid'],
                'data_hora_fechamento' => $unified['data_hora_fechamento'],
                'inicio_min' => $unified['data_hora_inicio'],
                'termino_max' => $unified['data_hora_termino'],
                'canais_presentes' => $unified['canais_presentes'],
                'canal_canonico' => $unified['canal_canonico'],
                'total_sistema_caixa' => $sistemaCaixa,
                'total_sistema_loja' => $sistemaLoja,
                'total_sistema_unificado' => $sistemaCaixa + $sistemaLoja,
                'total_declarado' => $unified['totais']['declarado'],
                'total_falta' => $unified['totais']['falta'],
                'total_sobra' => $unified['totais']['sobra'],
                'declared_consistent' => $unified['totais']['declared_consistent'],
                'has_loja_sales' => $hasLojaSales,
                'status' => 'closed_local',
                'last_sync_id' => $lastSyncId,
            ]
        );

        // Replace canonical payments (declarado/falta/sobra)
        PdvClosurePagamento::where('closure_uuid', $closureUuid)->delete();

        $paymentRows = [];
        foreach (['declarado', 'falta', 'sobra'] as $tipo) {
            foreach ($unified['pagamentos'][$tipo] ?? [] as $pag) {
                $paymentRows[] = [
                    'closure_uuid' => $closureUuid,
                    'tipo' => $tipo,
                    'id_finalizador' => $pag['id_finalizador'],
                    'meio_pagamento' => $pag['meio_pagamento'],
                    'total' => $pag['total'],
                    'qtd_vendas' => $pag['qtd_vendas'] ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (!empty($paymentRows)) {
            PdvClosurePagamento::insert($paymentRows);
        }

        return $closure;
    }

    /**
     * Determine the recommended cash total based on per-store rules.
     *
     * Default: CAIXA only. If store has include_loja_sales_in_cash=true, add LOJA.
     */
    public function getCashRecommended(int $storePdvId, float $caixa, float $loja): float
    {
        $rule = PdvCashRule::where('store_pdv_id', $storePdvId)->first();

        if ($rule && $rule->include_loja_sales_in_cash) {
            return $caixa + $loja;
        }

        return $caixa;
    }
}
