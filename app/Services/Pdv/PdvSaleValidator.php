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
            // Se for cancelada, pode ter total zerado ou nao, mas vamos checar a flag
            if (data_get($erp, 'Cancelada') === true) {
                return [
                    'ok' => true,
                    'found' => false,
                    'match_100' => false,
                    'reason' => 'Venda está CANCELADA no ERP.',
                    'status_erp' => 'CANCELLED',
                ];
            }

            return [
                'ok' => false,
                'error' => 'Campos mínimos ausentes: Data e/ou ValorTotalLiquido.',
            ];
        }

        // Checagem explicita de cancelamento mesmo com dados ok
        if (data_get($erp, 'Cancelada') === true) {
            return [
                'ok' => true,
                'found' => false, // Por padrao consideramos nao 'encontrada' como venda valida
                'match_100' => false,
                'reason' => 'Venda está marcada como CANCELADA no JSON do ERP.',
                'status_erp' => 'CANCELLED',
                'debug' => ['id_operacao' => $erpId, 'loja_id' => $lojaId]
            ];
        }

        // 3) Resolver store_pdv_id
        $storePdvId = $this->resolveStorePdvId($erp);

        if (!$storePdvId) {
            return [
                'ok' => false,
                'error' => 'Não consegui resolver store_pdv_id. Verifique se a loja está mapeada.',
                'debug' => ['LojaId' => $lojaId, 'NfeChave' => $nfeKey, 'NomeLoja' => data_get($erp, 'Loja.Nome')],
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

        // 5) BUSCA HIERARQUICA (Golden Key -> Fiscal -> Heuristica)

        $matchFn = function ($venda, $source) use ($erpId, $erpTotal) {
            return $this->buildMatchResult($venda, $source, $erpId, $erpTotal);
        };

        // --- NÍVEL 1: GOLDEN KEY (UUID da Operação) ---
        $erpOperacaoUuid = strtolower(trim((string) (data_get($erp, 'ErpOperacaoUuid') ?? data_get($erp, 'Id'))));
        $erpLojaUuid = $this->resolveErpLojaUuid($erp);

        if ($erpOperacaoUuid && $erpLojaUuid) {
            /** @var PdvVenda|null $goldenMatch */
            $goldenMatch = PdvVenda::where('erp_loja_uuid', $erpLojaUuid)
                ->where('erp_operacao_uuid', $erpOperacaoUuid)
                ->first();

            if ($goldenMatch) {
                return $matchFn($goldenMatch, 'golden_key_uuid');
            }
        }

        // --- NÍVEL 2: FISCAL KEY (Chave NFC-e) ---
        // $nfeKey já extraído acima
        if ($nfeKey && $erpLojaUuid) {
            $fiscalMatch = PdvVenda::where('erp_loja_uuid', $erpLojaUuid)
                ->where('nfce_chave', $nfeKey)
                ->first();

            if ($fiscalMatch) {
                return $matchFn($fiscalMatch, 'fiscal_key_nfce');
            }
        }

        // --- NÍVEL 3: FISCAL DADOS (Número + Série + Modelo + Data) ---
        // TODO: Implementar se necessário, mas geralmente Chave cobre tudo.

        // --- NÍVEL 4: HEURÍSTICA (Legacy Fallback) ---
        // (Continua com a lógica existente de janela de tempo + valor)

        // Buscar candidatos (assinatura: loja + total + janela)
        // Nota: Removido eager loading (with) pois a chave composta (store_pdv_id + id_operacao)
        // nao funciona bem com o padrao do Eloquent hasMany. Faremos load manual.
        $candidates = PdvVenda::query()
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
                'reason' => 'Nenhuma venda encontrada (Golden Key falhou, Fiscal Key falhou, Heurística vazia).',
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
            /** @var PdvVenda $venda */
            // Load manual dos relacionamentos com chave composta
            $dbItens = DB::table('pdv_venda_itens')
                ->where('store_pdv_id', $venda->store_pdv_id)
                ->where('id_operacao', $venda->id_operacao)
                ->get();

            $dbPagtos = DB::table('pdv_venda_pagamentos')
                ->where('store_pdv_id', $venda->store_pdv_id)
                ->where('id_operacao', $venda->id_operacao)
                ->get();

            // Hidratar os relacionamentos no modelo para o assinador usar
            $venda->setRelation('itens', $dbItens);
            $venda->setRelation('pagamentos', $dbPagtos);

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
                'match_type' => 'heuristic',
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

        // Enrich best match
        if ($bestCandidate) {
            $vendaModel = PdvVenda::find($bestCandidate['pdv_venda_id']);
            if ($vendaModel) {
                // Se foi match 100 heurístico, continua sendo um match válido
                $bestCandidate['db_details'] = $this->enrichMatchData($vendaModel);
            }
        }

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

    private function resolveErpLojaUuid(array $erp): ?string
    {
        $uuid = data_get($erp, 'LojaId') ?? data_get($erp, 'Loja.LojaId');
        return $uuid ? strtolower(trim($uuid)) : null;
    }

    private function buildMatchResult(PdvVenda $venda, string $matchType, $erpId, $erpTotal): array
    {
        // Para matches diretos (Gold/Fiscal), assumimos 100% de confiança
        // mas ainda podemos carregar os dados para detalhamento
        $enriched = $this->enrichMatchData($venda);

        return [
            'ok' => true,
            'found' => true,
            'match_100' => true, // Golden/Fiscal são considerados definitivos
            'best_match' => [
                'pdv_venda_id' => $venda->id,
                'id_operacao_db' => $venda->id_operacao,
                'erp_id_orig' => $erpId,
                'data_hora_utc' => optional($venda->data_hora)->toIso8601String(),
                'total' => (float) $venda->total,
                'match_type' => $matchType,
                'db_details' => $enriched,
            ],
            'all_candidates_count' => 1,
            'search' => ['type' => $matchType]
        ];
    }

    private function resolveStorePdvId(array $erp): ?int
    {
        // 1. Try match by GUID (LojaId) - Priority 1 (V5)
        $guidLoja = data_get($erp, 'LojaId') ?? data_get($erp, 'Loja.LojaId');
        if ($guidLoja) {
            // Check mappings first (most likely place for active stores)
            $mapping = DB::table('pdv_store_mappings')
                ->where('guid_loja', $guidLoja)
                ->where('active', true)
                ->first();

            if ($mapping) {
                return (int) $mapping->pdv_store_id;
            }

            // Check pdv_lojas direct table
            $store = DB::table('pdv_lojas')
                ->where('guid_loja', $guidLoja)
                ->first();

            if ($store) {
                return (int) $store->id_ponto_venda;
            }
        }

        // 2. Fallback to Name Matching (Legacy/V4)
        $lojaNome = data_get($erp, 'Loja.Nome');
        if ($lojaNome) {
            $store = DB::table('pdv_lojas')
                ->where('nome_hiper', $lojaNome)
                ->first();
            if ($store) {
                return (int) $store->id_ponto_venda;
            }
            // Tenta match parcial
            $store = DB::table('pdv_lojas')
                ->where('nome_hiper', 'like', '%' . $lojaNome . '%')
                ->first();
            if ($store)
                return (int) $store->id_ponto_venda;
        }

        // Fallback: Hardcoded map for known stores in this analysis context
        // Loja 12 - MC Porto Belo -> id_ponto_venda 13
        if (str_contains($lojaNome, 'Porto Belo'))
            return 13;
        if (str_contains($lojaNome, 'iTuntz'))
            return 4;
        if (str_contains($lojaNome, 'Loja 5') || str_contains($lojaNome, 'Komprão'))
            return 7;
        if (str_contains($lojaNome, 'Loja 7') || str_contains($lojaNome, 'Bombinhas'))
            return 6;

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
        // Relacionamento ja foi setado manualmente

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
        // Relacionamento ja foi setado manualmente

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

    private function enrichMatchData(PdvVenda $venda): array
    {
        // Carregar Store e Turno de forma Lazy para garantir que o 'where constraint' do model funcione
        // (venda->turno depende de venda->store_pdv_id)
        $loja = $venda->loja;
        $turno = $venda->turno;

        return [
            'store_db' => [
                'id' => $loja->id_ponto_venda ?? $venda->store_pdv_id,
                'nome_hiper' => $loja->nome_hiper ?? null,
            ],
            'user_db' => [
                'nome' => $turno->operador_nome ?? null,
                'login' => $turno->operador_login ?? null,
                'user_id' => $turno->operador_user_id ?? null,
            ],
            'timestamps' => [
                'data_venda' => $venda->data_hora?->toIso8601String(),
                'created_at' => $venda->created_at?->toIso8601String(),
                'updated_at' => $venda->updated_at?->toIso8601String(),
                'last_seen' => $venda->last_seen_in_snapshot_at?->toIso8601String(),
            ],
            'identifiers' => [
                'id_operacao' => $venda->id_operacao,
                'id_turno' => $venda->id_turno,
                'pdv_venda_id' => $venda->id,
            ]
        ];
    }
}
