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
        $erpTotal = (float) (data_get($erp, 'ValorTotalLiquido') ?? data_get($erp, 'total') ?? 0);
        $erpDate = data_get($erp, 'Data') ?? data_get($erp, 'data_hora'); // ex: 2026-02-14T11:44:11

        // Extração robusta do ID da Loja (UUID)
        $lojaId = data_get($erp, 'LojaId')
            ?? data_get($erp, 'Loja.LojaId')
            ?? data_get($erp, 'Turno.LojaId')
            ?? data_get($erp, 'Loja.Id');

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
                'debug' => [
                    'received_total' => $erpTotal,
                    'received_date' => $erpDate,
                    'keys_found' => array_keys($erp)
                ]
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

        // 3) Resolver store_pdv_id (Int)
        // Isso é crucial para busca heurística e performance, mas se tivermos UUIDs, podemos tentar Golden Match antes de falhar.
        $storePdvId = $this->resolveStorePdvId($erp);

        $erpLojaUuid = $this->resolveErpLojaUuid($erp);
        $erpOperacaoUuid = strtolower(trim((string) (data_get($erp, 'ErpOperacaoUuid') ?? data_get($erp, 'Id'))));

        // Se falhar a resolução da loja (ID Interno), só retornamos erro se NÃO tivermos UUIDs para tentar o Golden Match.
        // Se tiver UUID da Loja e da Operação, vamos tentar achar direto no banco, talvez a loja não esteja mapeada "localmente" (store_pdv_id) mas exista no banco (erp_loja_uuid).
        if (!$storePdvId && !($erpLojaUuid && $erpOperacaoUuid)) {
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

        // 6) Preparar assinaturas do ERP (Calculate EARLY so we can use in Golden Match too)
        $erpItemSig = $this->erpItemsSignature($erp);
        $erpPaySig = $this->erpPaymentsSignature($erp);

        $erpLojaUuid = $this->resolveErpLojaUuid($erp);
        $erpVendedorUuid = $this->resolveErpVendedorUuid($erp); // Helper to extract from items/user

        // 5) BUSCA HIERARQUICA (Golden Key -> Fiscal -> Heuristica)

        $matchFn = function ($venda, $source) use ($erpId, $erpTotal, $erpItemSig, $erpPaySig, $erpLojaUuid, $erpVendedorUuid, $erp) {
            return $this->buildMatchResult($venda, $source, $erpId, $erpTotal, $erpItemSig, $erpPaySig, $erpLojaUuid, $erpVendedorUuid, $erp);
        };

        // --- NÍVEL 1: GOLDEN KEY (UUID da Operação) ---
        $erpOperacaoUuid = strtolower(trim((string) (data_get($erp, 'ErpOperacaoUuid') ?? data_get($erp, 'Id'))));
        // $erpLojaUuid resolved above

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
        // Assinaturas já calculadas acima

        $ranked = [];
        foreach ($candidates as $venda) {
            /** @var PdvVenda $venda */
            // Load manual dos relacionamentos com chave composta e cálculo de assinaturas DB
            // (Agora feito dentro de enrichMatchData ou helper, mas aqui precisamos dos sigs para ranking)

            // Vamos usar o enrichMatchData para carregar relações se ainda não carregadas?
            // Não, o enrichMatchData formata para output. Aqui precisamos comparar.
            // Vou duplicar o load/sig aqui ou encapsular?
            // Melhor encapsular o Load.

            $this->loadRelationsManual($venda);

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
        $comparison = null;
        if ($bestCandidate) {
            $vendaModel = PdvVenda::find($bestCandidate['pdv_venda_id']);
            if ($vendaModel) {
                $this->loadRelationsManual($vendaModel);
                $bestCandidate['db_details'] = $this->enrichMatchData($vendaModel);
                $comparison = $this->buildComparison($erp, $vendaModel);
            }
        }

        return [
            'ok' => true,
            'found' => true,
            'match_100' => (bool) $best100,
            'best_match' => $bestCandidate,
            'comparison' => $comparison,
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
        $uuid = data_get($erp, 'LojaId')
            ?? data_get($erp, 'Loja.LojaId')
            ?? data_get($erp, 'Turno.LojaId'); // Adicionado fallback para payload V5 completo
        return $uuid ? strtolower(trim($uuid)) : null;
    }

    private function resolveErpVendedorUuid(array $erp): ?string
    {
        // Tenta pegar do primeiro item (onde geralmente vem)
        $firstItem = data_get($erp, 'Itens.0');
        if ($firstItem) {
            $uuid = data_get($firstItem, 'VendedorId');
            if ($uuid)
                return strtolower(trim($uuid));
        }

        // Tenta pegar de campos de usuário da operação (se existirem com esse nome no futuro)
        // Por hora, apenas Itens.VendedorId é garantido pelo exemplo do usuário.

        return null;
    }

    private function buildMatchResult(PdvVenda $venda, string $matchType, $erpId, $erpTotal, $erpItemSig, $erpPaySig, $erpLojaUuid, $erpVendedorUuid, array $erp): array
    {
        // Carregar relações para calcular assinaturas DB e enriquecer output
        $this->loadRelationsManual($venda);

        $dbItemSig = $this->dbItemsSignature($venda);
        $dbPaySig = $this->dbPaymentsSignature($venda);

        $itemsExact = ($erpItemSig == $dbItemSig);
        $payExact = ($erpPaySig == $dbPaySig);

        // --- Identity Matches (Store & Seller) ---
        $dbLojaUuid = strtolower(trim($venda->erp_loja_uuid ?? ''));
        if (!$dbLojaUuid && $venda->loja) {
            $dbLojaUuid = strtolower(trim($venda->loja->guid_loja ?? ''));
        }
        $storeIdentityMatch = $erpLojaUuid && $dbLojaUuid && ($erpLojaUuid === $dbLojaUuid);

        $dbVendedorUuid = null;
        $sellerIdentityMatch = false;

        if ($erpVendedorUuid) {
            foreach ($venda->itens as $item) {
                $itemGuid = strtolower(trim($item->vendedor_guid ?? ''));
                if ($itemGuid === $erpVendedorUuid) {
                    $sellerIdentityMatch = true;
                    $dbVendedorUuid = $itemGuid;
                    break;
                }
            }
        }

        $enriched = $this->enrichMatchData($venda);
        $comparison = $this->buildComparison($erp, $venda);

        return [
            'ok' => true,
            'found' => true,
            'match_100' => true,
            'content_match' => ($itemsExact && $payExact),
            'comparison' => $comparison,
            'best_match' => [
                'pdv_venda_id' => $venda->id,
                'id_operacao_db' => $venda->id_operacao,
                'erp_id_orig' => $erpId,
                'data_hora_utc' => optional($venda->data_hora)->toIso8601String(),
                'total' => (float) $venda->total,
                'match_type' => $matchType,
                'items_exact' => $itemsExact,
                'payments_exact' => $payExact,
                'store_identity_match' => $storeIdentityMatch,
                'seller_identity_match' => $sellerIdentityMatch,
                'db_details' => $enriched,
            ],
            'all_candidates_count' => 1,
            'search' => ['type' => $matchType]
        ];
    }

    private function loadRelationsManual(PdvVenda $venda): void
    {
        if ($venda->relationLoaded('itens') && $venda->relationLoaded('pagamentos')) {
            return;
        }

        $dbItens = DB::table('pdv_venda_itens')
            ->where('store_pdv_id', $venda->store_pdv_id)
            ->where('id_operacao', $venda->id_operacao)
            ->get();

        $dbPagtos = DB::table('pdv_venda_pagamentos')
            ->where('store_pdv_id', $venda->store_pdv_id)
            ->where('id_operacao', $venda->id_operacao)
            ->get();

        $venda->setRelation('itens', $dbItens);
        $venda->setRelation('pagamentos', $dbPagtos);
    }

    private function resolveStorePdvId(array $erp): ?int
    {
        // 1. Try match by GUID (LojaId) - Priority 1 (V5)
        $guidLoja = data_get($erp, 'LojaId')
            ?? data_get($erp, 'Loja.LojaId')
            ?? data_get($erp, 'Turno.LojaId'); // Adicionado fallback
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

        if (!$venda->relationLoaded('itens')) {
            return [];
        }

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
        if (!$venda->relationLoaded('pagamentos')) {
            return [];
        }

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
        $loja = $venda->loja;
        $turno = $venda->turno;
        $this->loadRelationsManual($venda);

        // Resolve seller info from users table (batch by unique guids)
        $sellerGuids = $venda->itens->pluck('vendedor_guid')->filter()->unique()->values()->all();
        $sellerMap = $this->resolveSellersByGuids($sellerGuids);

        $itemsFormatted = $venda->itens->map(function ($i) use ($sellerMap) {
            $guid = strtolower(trim($i->vendedor_guid ?? ''));
            $seller = $sellerMap[$guid] ?? null;
            return [
                'line_no' => (int) ($i->line_no ?? $i->id_item ?? 0),
                'codigo' => $i->codigo_barras ?? $i->id_produto,
                'nome' => $i->descricao ?? $i->descricao_reduzida ?? '',
                'qtd' => (float) $i->qtd,
                'preco_unit' => (float) ($i->preco_unit ?? $i->total),
                'total' => (float) $i->total,
                'desconto' => (float) ($i->desconto ?? 0),
                'vendedor_guid' => $i->vendedor_guid,
                'vendedor_nome' => $seller['name'] ?? $i->vendedor_nome,
                'vendedor_login' => $seller['login'] ?? $i->vendedor_login,
                'vendedor_user_id' => $seller['user_id'] ?? $i->vendedor_user_id,
                'vendedor_whatsapp' => $seller['whatsapp'] ?? null,
            ];
        })->values()->toArray();

        $paymentsFormatted = $venda->pagamentos->map(function ($p) {
            return [
                'line_no' => (int) ($p->line_no ?? $p->id_pagamento ?? 0),
                'meio' => $p->meio_pagamento,
                'valor' => (float) $p->valor,
                'troco' => (float) ($p->troco ?? 0),
                'parcelas' => (int) ($p->parcelas ?? 1),
            ];
        })->values()->toArray();

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
                'erp_operacao_uuid' => $venda->erp_operacao_uuid,
                'erp_loja_uuid' => $venda->erp_loja_uuid,
            ],
            'fiscal' => [
                'nfce_chave' => $venda->nfce_chave,
                'nfce_numero' => $venda->nfce_numero,
                'nfce_serie' => $venda->nfce_serie,
                'nfce_modelo' => $venda->nfce_modelo,
            ],
            'itens' => $itemsFormatted,
            'pagamentos' => $paymentsFormatted,
        ];
    }

    /**
     * Build a structured side-by-side comparison between ERP payload and DB data.
     */
    private function buildComparison(array $erp, PdvVenda $venda): array
    {
        $this->loadRelationsManual($venda);

        // ── Seller resolution ──
        $sellerGuids = $venda->itens->pluck('vendedor_guid')->filter()->unique()->values()->all();
        $sellerMap = $this->resolveSellersByGuids($sellerGuids);

        // ── 1. Operação ──
        $erpUuid = strtolower(trim(data_get($erp, 'Id') ?? ''));
        $dbUuid = strtolower(trim($venda->erp_operacao_uuid ?? ''));
        $erpTotal = (float) (data_get($erp, 'ValorTotalLiquido') ?? 0);
        $dbTotal = (float) $venda->total;

        $operacao = [
            'erp' => [
                'uuid' => data_get($erp, 'Id'),
                'codigo' => data_get($erp, 'CodigoDaOperacao'),
                'data' => data_get($erp, 'Data'),
                'total' => $erpTotal,
                'tipo' => data_get($erp, 'TipoDaOperacao'),
                'cancelada' => (bool) data_get($erp, 'Cancelada', false),
                'concluida' => (bool) data_get($erp, 'Concluida', false),
            ],
            'db' => [
                'uuid' => $venda->erp_operacao_uuid,
                'id_operacao' => $venda->id_operacao,
                'data' => $venda->data_hora?->toIso8601String(),
                'total' => $dbTotal,
                'canal' => $venda->canal,
                'turno_seq' => $venda->turno_seq,
                'status' => $venda->status ?? 'CONCLUIDO',
            ],
            'match' => [
                'uuid' => $erpUuid && $dbUuid && $erpUuid === $dbUuid,
                'total' => abs($erpTotal - $dbTotal) < 0.05,
            ],
        ];

        // ── 2. Loja ──
        $erpLojaUuid = strtolower(trim(data_get($erp, 'LojaId') ?? data_get($erp, 'Loja.Id') ?? ''));
        $dbLojaUuid = strtolower(trim($venda->erp_loja_uuid ?? ''));
        $loja = $venda->loja;

        $lojaSection = [
            'erp' => [
                'uuid' => data_get($erp, 'LojaId') ?? data_get($erp, 'Loja.Id'),
                'nome' => data_get($erp, 'Loja.Nome'),
            ],
            'db' => [
                'uuid' => $venda->erp_loja_uuid,
                'store_id' => $venda->store_id,
                'nome' => $loja->nome_hiper ?? null,
                'store_pdv_id' => $venda->store_pdv_id,
            ],
            'match' => $erpLojaUuid && $dbLojaUuid && $erpLojaUuid === $dbLojaUuid,
        ];

        // ── 3. Vendedor (from first item) ──
        $erpVendedorGuid = strtolower(trim(data_get($erp, 'Itens.0.VendedorId') ?? ''));
        $erpVendedorNome = data_get($erp, 'Itens.0.NomeDoVendedor');
        $dbFirstItem = $venda->itens->first();
        $dbVendedorGuid = $dbFirstItem ? strtolower(trim($dbFirstItem->vendedor_guid ?? '')) : '';
        $dbSeller = $dbVendedorGuid ? ($sellerMap[$dbVendedorGuid] ?? null) : null;

        $vendedorSection = [
            'erp' => [
                'guid' => data_get($erp, 'Itens.0.VendedorId'),
                'nome_no_item' => $erpVendedorNome,
            ],
            'db' => [
                'guid' => $dbFirstItem->vendedor_guid ?? null,
                'nome' => $dbSeller['name'] ?? $dbFirstItem->vendedor_nome ?? null,
                'login' => $dbSeller['login'] ?? $dbFirstItem->vendedor_login ?? null,
                'user_id' => $dbSeller['user_id'] ?? $dbFirstItem->vendedor_user_id ?? null,
                'whatsapp' => $dbSeller['whatsapp'] ?? null,
            ],
            'match' => $erpVendedorGuid && $dbVendedorGuid && $erpVendedorGuid === $dbVendedorGuid,
        ];

        // ── 4. Fiscal ──
        $erpChave = data_get($erp, 'DocumentosFiscais.0.Chave');
        $dbChave = $venda->nfce_chave;

        $fiscalSection = [
            'erp' => [
                'chave' => $erpChave,
                'numero' => data_get($erp, 'DocumentosFiscais.0.Numero'),
                'serie' => data_get($erp, 'DocumentosFiscais.0.SerieFiscal'),
                'modelo' => data_get($erp, 'DocumentosFiscais.0.ModeloDeDocumentoFiscalId'),
            ],
            'db' => [
                'chave' => $dbChave,
                'numero' => $venda->nfce_numero,
                'serie' => $venda->nfce_serie,
                'modelo' => $venda->nfce_modelo,
            ],
            'match' => $erpChave && $dbChave && $erpChave === $dbChave,
        ];

        // ── 5. Itens (side by side, matched by codigo) ──
        $erpItems = collect(data_get($erp, 'Itens', []))->sortBy('OrdemDeLancamento')->values();
        $dbItems = $venda->itens->sortBy(fn($i) => $i->line_no ?? $i->id_item ?? 0)->values();

        $itensSection = [];
        $maxItems = max($erpItems->count(), $dbItems->count());
        for ($idx = 0; $idx < $maxItems; $idx++) {
            $ei = $erpItems->get($idx);
            $di = $dbItems->get($idx);

            $diGuid = $di ? strtolower(trim($di->vendedor_guid ?? '')) : '';
            $diSeller = $diGuid ? ($sellerMap[$diGuid] ?? null) : null;

            $erpRow = $ei ? [
                'codigo' => data_get($ei, 'Codigo'),
                'nome' => data_get($ei, 'Nome'),
                'qtd' => (float) data_get($ei, 'Quantidade', 0),
                'preco_unit' => (float) data_get($ei, 'ValorUnitarioLiquido', 0),
                'total' => (float) data_get($ei, 'ValorTotalLiquido', 0),
                'vendedor' => data_get($ei, 'NomeDoVendedor'),
                'vendedor_guid' => data_get($ei, 'VendedorId'),
            ] : null;

            $dbRow = $di ? [
                'codigo' => $di->codigo_barras ?? $di->id_produto,
                'nome' => $di->descricao ?? $di->descricao_reduzida ?? '',
                'qtd' => (float) $di->qtd,
                'preco_unit' => (float) ($di->preco_unit ?? $di->total),
                'total' => (float) $di->total,
                'vendedor' => $diSeller['name'] ?? $di->vendedor_nome,
                'vendedor_guid' => $di->vendedor_guid,
            ] : null;

            $itemMatch = false;
            if ($erpRow && $dbRow) {
                $itemMatch = (string) $erpRow['codigo'] === (string) $dbRow['codigo']
                    && abs($erpRow['total'] - $dbRow['total']) < 0.01
                    && (int) $erpRow['qtd'] === (int) $dbRow['qtd'];
            }

            $itensSection[] = [
                'erp' => $erpRow,
                'db' => $dbRow,
                'match' => $itemMatch,
            ];
        }

        // ── 6. Pagamentos ──
        $erpPayGroups = collect(data_get($erp, 'MeiosDePagamentosAgrupados', []));
        $erpPays = collect();
        foreach ($erpPayGroups as $g) {
            foreach ((array) data_get($g, 'MeiosDePagamentos', []) as $p) {
                $erpPays->push($p);
            }
        }
        $dbPays = $venda->pagamentos;

        $pagamentosSection = [];
        $maxPays = max($erpPays->count(), $dbPays->count());
        for ($idx = 0; $idx < $maxPays; $idx++) {
            $ep = $erpPays->get($idx);
            $dp = $dbPays->get($idx);

            $erpPayRow = $ep ? [
                'meio' => data_get($ep, 'Descricao'),
                'valor' => (float) data_get($ep, 'Valor', 0),
                'troco' => (float) data_get($ep, 'Troco', 0),
                'parcela' => (int) data_get($ep, 'NumeroDaParcela', 1),
            ] : null;

            $dbPayRow = $dp ? [
                'meio' => $dp->meio_pagamento,
                'valor' => (float) $dp->valor,
                'troco' => (float) ($dp->troco ?? 0),
                'parcelas' => (int) ($dp->parcelas ?? 1),
            ] : null;

            $payMatch = false;
            if ($erpPayRow && $dbPayRow) {
                $payMatch = strtoupper($erpPayRow['meio']) === strtoupper($dbPayRow['meio'])
                    && abs($erpPayRow['valor'] - $dbPayRow['valor']) < 0.01;
            }

            $pagamentosSection[] = [
                'erp' => $erpPayRow,
                'db' => $dbPayRow,
                'match' => $payMatch,
            ];
        }

        // ── Summary flags ──
        $allItemsMatch = collect($itensSection)->every(fn($i) => $i['match']);
        $allPaysMatch = collect($pagamentosSection)->every(fn($p) => $p['match']);

        return [
            'operacao' => $operacao,
            'loja' => $lojaSection,
            'vendedor' => $vendedorSection,
            'fiscal' => $fiscalSection,
            'itens' => $itensSection,
            'pagamentos' => $pagamentosSection,
            'match_summary' => [
                'operacao_uuid' => $operacao['match']['uuid'],
                'total' => $operacao['match']['total'],
                'loja' => $lojaSection['match'],
                'vendedor' => $vendedorSection['match'],
                'fiscal' => $fiscalSection['match'],
                'all_itens' => $allItemsMatch,
                'all_pagamentos' => $allPaysMatch,
                'perfect' => $operacao['match']['uuid'] && $operacao['match']['total'] && $lojaSection['match'] && $fiscalSection['match'] && $allItemsMatch && $allPaysMatch,
            ],
        ];
    }

    /**
     * Resolve seller details from users table by their GUIDs.
     * Returns map: lowercase_guid => ['name', 'login', 'user_id', 'whatsapp']
     */
    private function resolveSellersByGuids(array $guids): array
    {
        if (empty($guids)) {
            return [];
        }

        $lowerGuids = array_map(fn($g) => strtolower(trim($g)), $guids);

        $users = DB::table('users')
            ->whereIn(DB::raw('LOWER(guid)'), $lowerGuids)
            ->select(['guid', 'name', 'login', 'id', 'whatsapp'])
            ->get();

        $map = [];
        foreach ($users as $u) {
            $key = strtolower(trim($u->guid ?? ''));
            $map[$key] = [
                'name' => $u->name,
                'login' => $u->login ?? null,
                'user_id' => $u->id,
                'whatsapp' => $u->whatsapp ?? null,
            ];
        }

        return $map;
    }
}

