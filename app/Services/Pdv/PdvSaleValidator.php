<?php

namespace App\Services\Pdv;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use App\Models\PdvVenda;
use Illuminate\Support\Facades\DB;

class PdvSaleValidator
{
    public function validateFromErpPayload(array $input): array
    {
        $timezone = $input['timezone'] ?? 'America/Sao_Paulo';
        $canal = $input['canal'] ?? null;

        $tolTotal = data_get($input, 'tolerance.total', 0.05);
        $minusMin = data_get($input, 'tolerance.start_minus_minutes', 10);
        $plusMin = data_get($input, 'tolerance.end_plus_minutes', 120);

        // 1) payload pode vir como string (textarea) ou como array
        $payload = $input['payload'];
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return [
                    'ok' => false,
                    'error' => 'JSON inválido no payload (textarea).',
                    'json_error' => json_last_error_msg(),
                ];
            }
            $erp = $decoded;
        } else {
            $erp = $payload;
        }

        // 2) Extrair campos principais do ERP
        $erpTotal = (float) data_get($erp, 'ValorTotalLiquido', 0);
        $erpDate = data_get($erp, 'Data'); // ex: 2026-02-14T11:44:11
        $lojaId = data_get($erp, 'LojaId') ?? data_get($erp, 'Loja.Id');
        $nfeKey = data_get($erp, 'DocumentosFiscais.0.Chave');
        $erpId = data_get($erp, 'CodigoDaOperacao');

        if (!$erpDate || $erpTotal <= 0) {
            return [
                'ok' => false,
                'error' => 'Campos mínimos ausentes: Data e/ou ValorTotalLiquido.',
            ];
        }

        // 3) Resolver store_pdv_id
        $storePdvId = $this->resolveStorePdvId($erp);

        if (!$storePdvId) {
            return [
                'ok' => false,
                'error' => 'Não consegui resolver store_pdv_id. Verifique se a loja está mapeada.',
                'debug' => ['LojaId' => $lojaId, 'NfeChave' => $nfeKey],
            ];
        }

        // 4) Normalizar tempo: ERP local -> UTC
        // Tenta parsear com timezone informado
        try {
            $targetUtc = Carbon::parse($erpDate, $timezone)->utc();
        } catch (\Exception $e) {
            return [
                'ok' => false,
                'error' => 'Data inválida ou timezone incorreto.',
                'debug' => ['Data' => $erpDate, 'Timezone' => $timezone],
            ];
        }

        $start = $targetUtc->copy()->subMinutes($minusMin);
        $end = $targetUtc->copy()->addMinutes($plusMin);

        // 5) Buscar candidatos (assinatura: loja + total + janela)
        $candidates = PdvVenda::query()
            ->with(['itens', 'pagamentos'])
            ->where('store_pdv_id', $storePdvId)
            ->when($canal, fn($q) => $q->where('canal', $canal))
            ->whereBetween('total', [$erpTotal - $tolTotal, $erpTotal + $tolTotal])
            ->whereBetween('data_hora', [$start, $end])
            ->limit(50)
            ->get();

        if ($candidates->isEmpty()) {
            return [
                'ok' => true, // Request ok, mas não achou venda
                'found' => false,
                'match_100' => false,
                'reason' => 'Nenhuma venda candidata encontrada (loja+total+janela).',
                'search' => [
                    'store_pdv_id' => $storePdvId,
                    'erp_id' => $erpId,
                    'total_target' => $erpTotal,
                    'window_utc' => [$start->toIso8601String(), $end->toIso8601String()],
                ],
            ];
        }

        // 6) Desempate/validação 100% por itens+pagamentos
        $erpItemSig = $this->erpItemsSignature($erp);
        $erpPaySig = $this->erpPaymentsSignature($erp);

        $ranked = [];
        foreach ($candidates as $venda) {
            $dbItemSig = $this->dbItemsSignature($venda);
            $dbPaySig = $this->dbPaymentsSignature($venda);

            // Comparação de arrays ordenados
            $itemsExact = ($erpItemSig == $dbItemSig);
            $payExact = ($erpPaySig == $dbPaySig);

            $ranked[] = [
                'pdv_venda_id' => $venda->id,
                'id_operacao_db' => $venda->id_operacao,
                'erp_id_orig' => $erpId,
                'data_hora_utc' => optional($venda->data_hora)->toIso8601String(),
                'total' => (float) $venda->total,
                'items_exact' => $itemsExact,
                'payments_exact' => $payExact,
                'match_100' => ($itemsExact && $payExact),
                'signatures' => [
                    'erp_items' => $erpItemSig,
                    'db_items' => $dbItemSig,
                    'erp_pay' => $erpPaySig,
                    'db_pay' => $dbPaySig
                ]
            ];
        }

        // preferir o primeiro match_100
        $best100 = collect($ranked)->firstWhere('match_100', true);

        // Se não tem match 100, pega o primeiro da lista (que já bateu total e horario)
        $bestCandidate = $best100 ?? $ranked[0];

        return [
            'ok' => true,
            'found' => true,
            'match_100' => (bool) $best100,
            'best_match' => $bestCandidate,
            'all_candidates_count' => count($ranked),
            'search' => [
                'store_pdv_id' => $storePdvId,
                'total_target' => $erpTotal,
                'window_utc' => [$start->toIso8601String(), $end->toIso8601String()],
            ],
        ];
    }

    private function resolveStorePdvId(array $erp): ?int
    {
        $lojaNome = data_get($erp, 'Loja.Nome');
        if ($lojaNome) {
            $store = DB::table('pdv_lojas')
                ->where('nome_hiper', $lojaNome)
                ->first();
            if ($store) {
                return $store->id_ponto_venda;
            }
            // Tenta match parcial
            $store = DB::table('pdv_lojas')
                ->where('nome_hiper', 'like', '%' . $lojaNome . '%')
                ->first();
            if ($store)
                return $store->id_ponto_venda;
        }

        // Fallback: Hardcoded map for known stores in this analysis context
        // Loja 12 - MC Porto Belo -> id_ponto_venda 13
        if (str_contains($lojaNome, 'Porto Belo'))
            return 13;
        if (str_contains($lojaNome, 'iTuntz'))
            return 4;

        return null;
    }


    private function erpItemsSignature(array $erp): array
    {
        $items = data_get($erp, 'Itens', []);
        $sig = [];
        foreach ($items as $i) {
            $sig[] = [
                'codigo' => (string) data_get($i, 'Codigo'),
                'qtd' => (float) data_get($i, 'Quantidade', 0),
                'total' => round((float) data_get($i, 'ValorTotalLiquido', 0), 2),
            ];
        }
        // Ordenar para garantir comparação consistente
        usort($sig, fn($a, $b) => $a['codigo'] <=> $b['codigo']);
        return $sig;
    }

    private function erpPaymentsSignature(array $erp): array
    {
        $groups = data_get($erp, 'MeiosDePagamentosAgrupados', []);
        $sig = [];
        foreach ($groups as $g) {
            foreach ((array) data_get($g, 'MeiosDePagamentos', []) as $p) {
                $desc = (string) data_get($p, 'Descricao');
                $sig[] = [
                    'meio' => $this->normalizePaymentName($desc),
                    'valor' => round((float) data_get($p, 'Valor', 0), 2),
                    // Troco geralmente é 0 no item, mas vamos considerar
                    // 'troco' => round((float) data_get($p, 'Troco', 0), 2), 
                ];
            }
        }
        usort($sig, fn($a, $b) => $a['valor'] <=> $b['valor']);
        return $sig;
    }

    private function dbItemsSignature($venda): array
    {
        $sig = [];
        // Carrega relacionamento se nao vier
        if (!$venda->relationLoaded('itens'))
            $venda->load('itens');

        foreach ($venda->itens as $i) {
            $sig[] = [
                'codigo' => (string) ($i->codigo_barras ?? $i->id_produto ?? ''),
                'qtd' => (float) $i->qtd,
                'total' => round((float) $i->total, 2),
            ];
        }
        usort($sig, fn($a, $b) => $a['codigo'] <=> $b['codigo']);
        return $sig;
    }

    private function dbPaymentsSignature($venda): array
    {
        $sig = [];
        if (!$venda->relationLoaded('pagamentos'))
            $venda->load('pagamentos');

        foreach ($venda->pagamentos as $p) {
            $sig[] = [
                'meio' => $this->normalizePaymentName($p->meio_pagamento),
                'valor' => round((float) $p->valor, 2),
                // 'troco' => round((float) ($p->troco ?? 0), 2),
            ];
        }
        usort($sig, fn($a, $b) => $a['valor'] <=> $b['valor']);
        return $sig;
    }

    private function normalizePaymentName(string $name): string
    {
        return strtoupper(trim($name));
    }
}
