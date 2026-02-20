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
                    'error' => 'JSON invÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¡lido no payload (textarea).',
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
        $isCancelled = (bool) data_get($erp, 'Cancelada', false);

        // ExtraÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â£o robusta do ID da Loja (UUID)
        $lojaId = data_get($erp, 'LojaId')
            ?? data_get($erp, 'Loja.LojaId')
            ?? data_get($erp, 'Turno.LojaId')
            ?? data_get($erp, 'Loja.Id');

        $nfeKey = data_get($erp, 'DocumentosFiscais.0.Chave');
        $erpId = data_get($erp, 'CodigoDaOperacao');

        if ((!$erpDate || $erpTotal <= 0) && !$isCancelled) {

            return [
                'ok' => false,
                'error' => 'Campos mÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â­nimos ausentes: Data e/ou ValorTotalLiquido.',
                'debug' => [
                    'received_total' => $erpTotal,
                    'received_date' => $erpDate,
                    'keys_found' => array_keys($erp)
                ]
            ];
        }

        // 3) Resolver store_pdv_id (Int)
        // Isso ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â© crucial para busca heurÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â­stica e performance, mas se tivermos UUIDs, podemos tentar Golden Match antes de falhar.
        $storePdvId = $this->resolveStorePdvId($erp);

        $erpLojaUuid = $this->resolveErpLojaUuid($erp);
        $erpOperacaoUuid = strtolower(trim((string) (data_get($erp, 'ErpOperacaoUuid') ?? data_get($erp, 'Id'))));

        // Se falhar a resoluÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â£o da loja (ID Interno), sÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³ retornamos erro se NÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢O tivermos UUIDs para tentar o Golden Match.
        // Se tiver UUID da Loja e da OperaÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â£o, vamos tentar achar direto no banco, talvez a loja nÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â£o esteja mapeada "localmente" (store_pdv_id) mas exista no banco (erp_loja_uuid).
        if (!$storePdvId && !($erpLojaUuid && $erpOperacaoUuid)) {
            return [
                'ok' => false,
                'error' => 'NÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â£o consegui resolver store_pdv_id. Verifique se a loja estÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¡ mapeada.',
                'debug' => ['LojaId' => $lojaId, 'NfeChave' => $nfeKey, 'NomeLoja' => data_get($erp, 'Loja.Nome')],
            ];
        }


        // 4) Normalizar tempo: ERP local -> UTC (quando disponivel)
        $targetUtc = null;
        $start = null;
        $end = null;
        if (!empty($erpDate)) {
            try {
                $targetUtc = Carbon::parse($erpDate, $timezone)->utc();
                $start = $targetUtc->copy()->subMinutes($minusMin);
                $end = $targetUtc->copy()->addMinutes($plusMin);
            } catch (\Exception $e) {
                if (!$isCancelled) {
                    return [
                        'ok' => false,
                        'error' => 'Data invÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¡lida ou timezone incorreto.',
                        'debug' => ['Data' => $erpDate, 'Timezone' => $timezone],
                    ];
                }
            }
        }

        // 6) Preparar assinaturas do ERP (Calculate EARLY so we can use in Golden Match too)
        $erpItemSig = $this->erpItemsSignature($erp);
        $erpPaySig = $this->erpPaymentsSignature($erp);

        $erpLojaUuid = $this->resolveErpLojaUuid($erp);
        $erpVendedorUuid = $this->resolveErpVendedorUuid($erp); // Helper to extract from items/user

        // 5) BUSCA HIERARQUICA (Golden Key -> Fiscal -> Heuristica)

        $matchFn = function ($venda, $source) use ($erpId, $erpTotal, $erpItemSig, $erpPaySig, $erpLojaUuid, $erpVendedorUuid, $erp) {
            return $this->buildMatchResult($venda, $source, $erpId, $erpTotal, $erpItemSig, $erpPaySig, $erpLojaUuid, $erpVendedorUuid, $erp);
        };

        // --- NÃƒÆ’Ã†â€™Ãƒâ€šÃ‚ÂVEL 1: GOLDEN KEY (UUID da OperaÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â£o) ---
        $erpOperacaoUuid = strtolower(trim((string) (data_get($erp, 'ErpOperacaoUuid') ?? data_get($erp, 'Id'))));
        // $erpLojaUuid resolved above

        if ($erpOperacaoUuid && $erpLojaUuid) {
            /** @var PdvVenda|null $goldenMatch */
            $goldenMatch = PdvVenda::whereRaw('LOWER(erp_loja_uuid) = ?', [strtolower($erpLojaUuid)])
                ->whereRaw('LOWER(erp_operacao_uuid) = ?', [strtolower($erpOperacaoUuid)])
                ->first();

            if ($goldenMatch) {
                return $matchFn($goldenMatch, 'golden_key_uuid');
            }
        }

        // Fallback robusto: quando erp_loja_uuid local estiver legado/inconsistente,
        // tenta casar pelo UUID da operacao e desempata por contexto de loja.
        if ($erpOperacaoUuid) {
            $uuidCandidates = PdvVenda::query()
                ->whereRaw('LOWER(erp_operacao_uuid) = ?', [strtolower($erpOperacaoUuid)])
                ->when($canal, fn($q) => $q->where('canal', $canal))
                ->limit(10)
                ->get();

            if ($uuidCandidates->isNotEmpty()) {
                $resolvedStoreId = $this->resolveStoreIdByErpLojaUuid($erpLojaUuid);
                $uuidMatch = $this->pickBestOperationUuidCandidate(
                    $uuidCandidates,
                    $erpLojaUuid,
                    $storePdvId,
                    $resolvedStoreId,
                    $erpTotal,
                    $targetUtc
                );

                if ($uuidMatch) {
                    return $matchFn($uuidMatch, 'uuid');
                }
            }
        }

        // --- NÃƒÆ’Ã†â€™Ãƒâ€šÃ‚ÂVEL 2: FISCAL KEY (Chave NFC-e) ---
        // $nfeKey jÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¡ extraÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â­do acima
        if ($nfeKey) {
            $fiscalMatch = null;

            if ($erpLojaUuid) {
                $fiscalMatch = PdvVenda::whereRaw('LOWER(erp_loja_uuid) = ?', [strtolower($erpLojaUuid)])
                    ->where('nfce_chave', $nfeKey)
                    ->when($canal, fn($q) => $q->where('canal', $canal))
                    ->first();
            }

            if (!$fiscalMatch) {
                $fiscalMatch = PdvVenda::query()
                    ->where('nfce_chave', $nfeKey)
                    ->when($canal, fn($q) => $q->where('canal', $canal))
                    ->first();
            }

            if ($fiscalMatch) {
                return $matchFn($fiscalMatch, 'fiscal_key_nfce');
            }
        }

        // --- NÃƒÆ’Ã†â€™Ãƒâ€šÃ‚ÂVEL 3: FISCAL DADOS (NÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Âºmero + SÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©rie + Modelo + Data) ---
        // TODO: Implementar se necessÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¡rio, mas geralmente Chave cobre tudo.

        // --- NÃƒÆ’Ã†â€™Ãƒâ€šÃ‚ÂVEL 4: HEURÃƒÆ’Ã†â€™Ãƒâ€šÃ‚ÂSTICA (Legacy Fallback) ---
        // (Continua com a lÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³gica existente de janela de tempo + valor)

        if (!$storePdvId || $start === null || $end === null || $erpTotal <= 0) {
            $statusValidation = $this->resolveStatusValidation($erp, null);

            return [
                'ok' => true,
                'found' => false,
                'match_100' => false,
                'reason' => $isCancelled
                    ? 'Venda cancelada no ERP, mas sem correspondencia local por UUID/chave fiscal.'
                    : 'Dados insuficientes para busca heuristica.',
                'status_erp' => $statusValidation['status_erp'],
                'status_db' => $statusValidation['status_db'],
                'expected_status_db' => $statusValidation['expected_status_db'],
                'status_match' => $statusValidation['status_match'],
                'search' => [
                    'store_pdv_id' => $storePdvId,
                    'erp_id' => $erpId,
                    'total_target' => $erpTotal,
                    'window_utc' => $targetUtc ? [$start?->toIso8601String(), $end?->toIso8601String()] : null,
                    'missing_inputs' => array_values(array_filter([
                        $storePdvId ? null : 'store_pdv_id',
                        ($start && $end) ? null : 'data_hora',
                        $erpTotal > 0 ? null : 'valor_total',
                    ])),
                ],
            ];
        }

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
            $statusValidation = $this->resolveStatusValidation($erp, null);

            return [
                'ok' => true, // Request ok, mas nÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â£o achou venda
                'found' => false,
                'match_100' => false,
                'reason' => $isCancelled
                    ? 'Venda cancelada no ERP sem correspondencia no banco (UUID/chave fiscal/heuristica).'
                    : 'Nenhuma venda encontrada (Golden Key falhou, Fiscal Key falhou, HeurÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â­stica vazia).',
                'status_erp' => $statusValidation['status_erp'],
                'status_db' => $statusValidation['status_db'],
                'expected_status_db' => $statusValidation['expected_status_db'],
                'status_match' => $statusValidation['status_match'],
                'search' => [
                    'store_pdv_id' => $storePdvId,
                    'erp_id' => $erpId,
                    'total_target' => $erpTotal,
                    'window_utc' => [$start->toIso8601String(), $end->toIso8601String()],
                ],
            ];
        }

        // 6) Desempate/validaÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â£o 100% por itens+pagamentos
        // Assinaturas jÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¡ calculadas acima

        $ranked = [];
        foreach ($candidates as $venda) {
            /** @var PdvVenda $venda */
            // Load manual dos relacionamentos com chave composta e cÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¡lculo de assinaturas DB
            // (Agora feito dentro de enrichMatchData ou helper, mas aqui precisamos dos sigs para ranking)

            // Vamos usar o enrichMatchData para carregar relaÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Âµes se ainda nÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â£o carregadas?
            // NÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â£o, o enrichMatchData formata para output. Aqui precisamos comparar.
            // Vou duplicar o load/sig aqui ou encapsular?
            // Melhor encapsular o Load.

            $this->loadRelationsManual($venda);

            $dbItemSig = $this->dbItemsSignature($venda);
            $dbPaySig = $this->dbPaymentsSignature($venda);

            // ComparaÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â£o de arrays ordenados
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

        // Se nÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â£o tem match 100, pega o primeiro da lista (que jÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¡ bateu total e horario)
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

        $statusValidation = $this->resolveStatusValidation(
            $erp,
            data_get($bestCandidate, 'db_details.venda.status')
        );

        return [
            'ok' => true,
            'found' => true,
            'match_100' => (bool) $best100,
            'status_erp' => $statusValidation['status_erp'],
            'status_db' => $statusValidation['status_db'],
            'expected_status_db' => $statusValidation['expected_status_db'],
            'status_match' => $statusValidation['status_match'],
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

        // Tenta pegar de campos de usuÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¡rio da operaÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â£o (se existirem com esse nome no futuro)
        // Por hora, apenas Itens.VendedorId ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â© garantido pelo exemplo do usuÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¡rio.

        return null;
    }

    private function buildMatchResult(PdvVenda $venda, string $matchType, $erpId, $erpTotal, $erpItemSig, $erpPaySig, $erpLojaUuid, $erpVendedorUuid, array $erp): array
    {
        // Carregar relaÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Âµes para calcular assinaturas DB e enriquecer output
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
        $statusValidation = $this->resolveStatusValidation($erp, $venda->status ?? null);

        return [
            'ok' => true,
            'found' => true,
            'match_100' => true,
            'content_match' => ($itemsExact && $payExact),
            'status_erp' => $statusValidation['status_erp'],
            'status_db' => $statusValidation['status_db'],
            'expected_status_db' => $statusValidation['expected_status_db'],
            'status_match' => $statusValidation['status_match'],
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
                'status_match' => $statusValidation['status_match'],
                'expected_status_db' => $statusValidation['expected_status_db'],
                'status_db' => $statusValidation['status_db'],
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
            $guidLojaLower = strtolower(trim((string) $guidLoja));

            // Check mappings first (most likely place for active stores)
            $mappingIds = DB::table('pdv_store_mappings')
                ->whereRaw('LOWER(guid_loja) = ?', [$guidLojaLower])
                ->where('active', true)
                ->pluck('pdv_store_id')
                ->map(fn($id) => (int) $id)
                ->filter(fn($id) => $id > 0)
                ->values()
                ->all();

            $preferredFromMappings = $this->pickPreferredStorePdvId($mappingIds);
            if ($preferredFromMappings !== null) {
                return $preferredFromMappings;
            }

            // Resolve by stores.guid first, then project to pdv_store_mappings by store_id.
            $storeIdFromGuid = DB::table('stores')
                ->whereRaw('LOWER(guid) = ?', [$guidLojaLower])
                ->value('id');

            if ($storeIdFromGuid !== null) {
                $mappingIdsByStore = DB::table('pdv_store_mappings')
                    ->where('store_id', (int) $storeIdFromGuid)
                    ->where('active', true)
                    ->pluck('pdv_store_id')
                    ->map(fn($id) => (int) $id)
                    ->filter(fn($id) => $id > 0)
                    ->values()
                    ->all();

                $preferredFromStoreGuid = $this->pickPreferredStorePdvId($mappingIdsByStore);
                if ($preferredFromStoreGuid !== null) {
                    return $preferredFromStoreGuid;
                }
            }

            // Check pdv_lojas direct table (support both guid_loja and legacy guid column names).
            $pdvLojaIds = [];
            if (\Illuminate\Support\Facades\Schema::hasColumn('pdv_lojas', 'guid_loja')) {
                $pdvLojaIds = DB::table('pdv_lojas')
                    ->whereRaw('LOWER(guid_loja) = ?', [$guidLojaLower])
                    ->pluck('id_ponto_venda')
                    ->map(fn($id) => (int) $id)
                    ->filter(fn($id) => $id > 0)
                    ->values()
                    ->all();
            } elseif (\Illuminate\Support\Facades\Schema::hasColumn('pdv_lojas', 'guid')) {
                $pdvLojaIds = DB::table('pdv_lojas')
                    ->whereRaw('LOWER(guid) = ?', [$guidLojaLower])
                    ->pluck('id_ponto_venda')
                    ->map(fn($id) => (int) $id)
                    ->filter(fn($id) => $id > 0)
                    ->values()
                    ->all();
            }

            $preferredFromPdvLojas = $this->pickPreferredStorePdvId($pdvLojaIds);
            if ($preferredFromPdvLojas !== null) {
                return $preferredFromPdvLojas;
            }
        }

        // 2. Fallback to Name Matching (Legacy/V4)
        $lojaNome = (string) (data_get($erp, 'Loja.Nome') ?? data_get($erp, 'NomeDaLoja') ?? '');
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
        if ($lojaNome !== '') {
            if (str_contains($lojaNome, 'Porto Belo')) {
                return 13;
            }
            if (str_contains($lojaNome, 'iTuntz')) {
                return 4;
            }
            if (str_contains($lojaNome, 'Loja 5') || str_contains($lojaNome, 'Komprão')) {
                return 7;
            }
            if (str_contains($lojaNome, 'Loja 7') || str_contains($lojaNome, 'Bombinhas')) {
                return 6;
            }
        }

        return null;
    }

    /**
     * @param array<int, int> $candidateIds
     */
    private function pickPreferredStorePdvId(array $candidateIds): ?int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $candidateIds), fn($id) => $id > 0)));
        if ($ids === []) {
            return null;
        }

        if (count($ids) === 1) {
            return $ids[0];
        }

        $recent = DB::table('pdv_vendas')
            ->whereIn('store_pdv_id', $ids)
            ->orderByDesc('data_hora')
            ->value('store_pdv_id');

        if ($recent !== null) {
            return (int) $recent;
        }

        sort($ids);
        return $ids[0];
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
        // Ordenar para garantir comparaÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â£o consistente
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
                    // Troco geralmente ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â© 0 no item, mas vamos considerar
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

        // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ Store info from stores table ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
        $storeInfo = $this->resolveStoreInfoForVenda($venda);

        // Resolve seller info from users table (batch by unique guids)
        $sellerGuids = $venda->itens->pluck('vendedor_guid')->filter()->unique()->values()->all();
        $sellerMap = $this->resolveSellersByGuids($sellerGuids);

        $itemsFormatted = $venda->itens->map(function ($i) use ($sellerMap) {
            $guid = strtolower(trim($i->vendedor_guid ?? ''));
            $seller = $sellerMap[$guid] ?? null;
            $total = (float) $i->total;
            $desconto = (float) ($i->desconto ?? 0);
            $qtd = (float) $i->qtd;
            return [
                'line_no' => (int) ($i->line_no ?? $i->id_item ?? 0),
                'id_produto' => $i->id_produto ?? null,
                'codigo_barras' => $i->codigo_barras ?? null,
                'nome_produto' => $i->descricao ?? $i->descricao_reduzida ?? $i->nome_produto ?? '',
                'qtd' => $qtd,
                'preco_unit' => (float) ($i->preco_unit ?? $total),
                'total' => $total,
                'desconto' => $desconto,
                'valor_original' => round($total + $desconto, 2),
                'preco_original' => $qtd > 0 ? round(($total + $desconto) / $qtd, 2) : 0.0,
                'vendedor_pdv_id' => $i->vendedor_pdv_id ?? null,
                'vendedor_guid' => $i->vendedor_guid,
                'vendedor_nome' => $seller['name'] ?? $i->vendedor_nome,
                'vendedor_login' => $i->vendedor_login ?? null,
                'vendedor_user_id' => $seller['user_id'] ?? $i->vendedor_user_id,
                'vendedor_whatsapp' => $seller['whatsapp'] ?? null,
            ];
        })->values()->toArray();

        $paymentsFormatted = $venda->pagamentos->map(function ($p) {
            return [
                'line_no' => (int) ($p->line_no ?? $p->id_pagamento ?? 0),
                'id_finalizador' => $p->id_finalizador ?? null,
                'meio_pagamento' => $p->meio_pagamento,
                'valor' => (float) $p->valor,
                'troco' => (float) ($p->troco ?? 0),
                'parcelas' => (int) ($p->parcelas ?? 1),
            ];
        })->values()->toArray();

        // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ Summary ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
        $itensCount = count($itemsFormatted);
        $itensQtdTotal = round(array_sum(array_column($itemsFormatted, 'qtd')), 3);
        $itensValorTotal = round(array_sum(array_column($itemsFormatted, 'total')), 2);
        $itensDescontoTotal = round(array_sum(array_column($itemsFormatted, 'desconto')), 2);

        $pagamentosCount = count($paymentsFormatted);
        $pagamentosValorTotal = round(array_sum(array_column($paymentsFormatted, 'valor')), 2);
        $pagamentosTrocoTotal = round(array_sum(array_column($paymentsFormatted, 'troco')), 2);

        return [
            'venda' => [
                'store_id' => $storeInfo['id'],
                'store_name' => $storeInfo['name'],
                'store_city' => $storeInfo['city'],
                'store_pdv_id' => $venda->store_pdv_id,
                'store_pdv_name' => $loja->nome_padronizado ?? $loja->nome_hiper ?? null,
                'store_cnpj' => $storeInfo['cnpj'],
                'store_razao_social' => $storeInfo['razao_social'],
                'canal' => $venda->canal,
                'id_operacao' => $venda->id_operacao,
                'id_turno' => $venda->id_turno,
                'turno_seq' => $venda->turno_seq,
                'data_hora' => $venda->data_hora?->toIso8601String(),
                'total' => (float) $venda->total,
                'status' => $venda->status ?? null,
                'erp_operacao_uuid' => $venda->erp_operacao_uuid,
                'erp_loja_uuid' => $venda->erp_loja_uuid ?: $storeInfo['guid'],
                'fiscal' => [
                    'nfce_chave' => $venda->nfce_chave,
                    'nfce_numero' => $venda->nfce_numero,
                    'nfce_serie' => $venda->nfce_serie,
                    'nfce_modelo' => $venda->nfce_modelo,
                ],
            ],
            'itens' => $itemsFormatted,
            'pagamentos' => $paymentsFormatted,
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
        ];
    }

    /**
     * Build a structured side-by-side comparison between ERP payload and DB data.
     */
    private function buildComparison(array $erp, PdvVenda $venda): array
    {
        $this->loadRelationsManual($venda);
        $storeInfo = $this->resolveStoreInfoForVenda($venda);

        // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ Seller resolution ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
        $sellerGuids = $venda->itens->pluck('vendedor_guid')->filter()->unique()->values()->all();
        $sellerMap = $this->resolveSellersByGuids($sellerGuids);

        // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ 1. OperaÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â£o ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
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

        // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ 2. Loja ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
        $erpLojaUuid = strtolower(trim(data_get($erp, 'LojaId') ?? data_get($erp, 'Loja.Id') ?? data_get($erp, 'Turno.LojaId') ?? ''));
        $dbLojaUuid = strtolower(trim($venda->erp_loja_uuid ?: ($storeInfo['guid'] ?? '')));
        $loja = $venda->loja;

        $lojaSection = [
            'erp' => [
                'uuid' => data_get($erp, 'LojaId') ?? data_get($erp, 'Loja.Id') ?? data_get($erp, 'Turno.LojaId'),
                'nome' => data_get($erp, 'Loja.Nome') ?? data_get($erp, 'NomeDaLoja'),
            ],
            'db' => [
                'uuid' => $venda->erp_loja_uuid ?: $storeInfo['guid'],
                'store_id' => $storeInfo['id'],
                'nome' => $storeInfo['name'] ?? ($loja->nome_hiper ?? null),
                'cidade' => $storeInfo['city'],
                'store_pdv_id' => $venda->store_pdv_id,
            ],
            'match' => $erpLojaUuid && $dbLojaUuid && $erpLojaUuid === $dbLojaUuid,
        ];

        // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ 3. Vendedor (from first item) ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
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
                'login' => $dbFirstItem->vendedor_login ?? null,
                'user_id' => $dbSeller['user_id'] ?? $dbFirstItem->vendedor_user_id ?? null,
                'whatsapp' => $dbSeller['whatsapp'] ?? null,
            ],
            'match' => $erpVendedorGuid && $dbVendedorGuid && $erpVendedorGuid === $dbVendedorGuid,
        ];

        // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ 4. Fiscal ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
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

        // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ 5. Itens (side by side, matched by codigo) ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
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

        // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ 6. Pagamentos ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
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

        // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ Summary flags ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
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
     * @return array{status_erp:string,status_db:string|null,expected_status_db:string|null,status_match:bool|null}
     */
    private function resolveStatusValidation(array $erp, ?string $dbStatus): array
    {
        $statusErp = $this->resolveErpStatusLabel($erp);
        $expectedDbStatus = $this->resolveExpectedDbStatus($erp);
        $normalizedDbStatus = $this->normalizeDbStatus($dbStatus);

        $statusMatch = null;
        if ($expectedDbStatus !== null && $normalizedDbStatus !== null) {
            $statusMatch = $expectedDbStatus === $normalizedDbStatus;
        }

        return [
            'status_erp' => $statusErp,
            'status_db' => $normalizedDbStatus,
            'expected_status_db' => $expectedDbStatus,
            'status_match' => $statusMatch,
        ];
    }

    private function resolveErpStatusLabel(array $erp): string
    {
        if ((bool) data_get($erp, 'Cancelada', false)) {
            return 'CANCELLED';
        }

        if ((bool) data_get($erp, 'Concluida', false)) {
            return 'COMPLETED';
        }

        return 'UNKNOWN';
    }

    private function resolveExpectedDbStatus(array $erp): ?string
    {
        if ((bool) data_get($erp, 'Cancelada', false)) {
            return 'CANCELADO';
        }

        if ((bool) data_get($erp, 'Concluida', false)) {
            return 'CONCLUIDO';
        }

        return null;
    }

    private function resolveStoreIdByErpLojaUuid(?string $erpLojaUuid): ?int
    {
        if ($erpLojaUuid === null || !$this->isUuidLike($erpLojaUuid)) {
            return null;
        }

        $storeId = DB::table('stores')
            ->whereRaw('LOWER(guid) = ?', [strtolower($erpLojaUuid)])
            ->value('id');

        return $storeId !== null ? (int) $storeId : null;
    }

    /**
     * @return array{id:int|null,guid:string|null,name:string|null,city:string|null,cnpj:string|null,razao_social:string|null}
     */
    private function resolveStoreInfoForVenda(PdvVenda $venda): array
    {
        $storeInfo = null;
        $select = ['id', 'guid', 'name', 'city', 'cnpj', 'razao_social'];

        if ($venda->store_id) {
            $storeInfo = DB::table('stores')
                ->where('id', (int) $venda->store_id)
                ->select($select)
                ->first();
        }

        if ($storeInfo === null && $venda->erp_loja_uuid) {
            $storeInfo = DB::table('stores')
                ->whereRaw('LOWER(guid) = ?', [strtolower(trim((string) $venda->erp_loja_uuid))])
                ->select($select)
                ->first();
        }

        return [
            'id' => $storeInfo?->id !== null ? (int) $storeInfo->id : ($venda->store_id ? (int) $venda->store_id : null),
            'guid' => $storeInfo->guid ?? ($venda->erp_loja_uuid ?: null),
            'name' => $storeInfo->name ?? null,
            'city' => $storeInfo->city ?? null,
            'cnpj' => $storeInfo->cnpj ?? null,
            'razao_social' => $storeInfo->razao_social ?? null,
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, PdvVenda> $candidates
     */
    private function pickBestOperationUuidCandidate($candidates, ?string $erpLojaUuid, ?int $storePdvId, ?int $storeId, float $erpTotal, ?Carbon $targetUtc): ?PdvVenda
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $best = null;
        $bestScore = -INF;
        $erpLojaUuidNorm = $erpLojaUuid ? strtolower(trim($erpLojaUuid)) : null;

        foreach ($candidates as $candidate) {
            if (!$candidate instanceof PdvVenda) {
                continue;
            }

            $score = 0.0;

            $dbLojaUuidNorm = strtolower(trim((string) ($candidate->erp_loja_uuid ?? '')));
            if ($erpLojaUuidNorm && $dbLojaUuidNorm !== '' && $erpLojaUuidNorm === $dbLojaUuidNorm) {
                $score += 100;
            }

            if ($storePdvId && (int) $candidate->store_pdv_id === $storePdvId) {
                $score += 60;
            }

            if ($storeId && (int) $candidate->store_id === $storeId) {
                $score += 40;
            }

            $score -= abs(((float) $candidate->total) - $erpTotal);

            if ($targetUtc && $candidate->data_hora) {
                try {
                    $dbUtc = Carbon::parse($candidate->data_hora)->utc();
                    $minutesDiff = abs($dbUtc->diffInMinutes($targetUtc));
                    $score += max(0, 20 - min(20, $minutesDiff));
                } catch (\Throwable $e) {
                    // Ignore parse issues in tie-break scoring.
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }

    private function isUuidLike(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', trim($value));
    }

    private function normalizeDbStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $normalized = strtoupper(trim($status));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'CANCELADA', 'CANCELADO', 'CANCELLED' => 'CANCELADO',
            'CONCLUIDA', 'CONCLUIDO', 'COMPLETED' => 'CONCLUIDO',
            default => $normalized,
        };
    }

    /**
     * Resolve seller details from users table by their GUIDs.
     * Returns map: lowercase_guid => ['name', 'user_id', 'whatsapp']
     */
    private function resolveSellersByGuids(array $guids): array
    {
        if (empty($guids)) {
            return [];
        }

        $lowerGuids = array_map(fn($g) => strtolower(trim($g)), $guids);

        $users = DB::table('users')
            ->whereIn(DB::raw('LOWER(guid)'), $lowerGuids)
            ->select(['guid', 'name', 'id', 'whatsapp'])
            ->get();

        $map = [];
        foreach ($users as $u) {
            $key = strtolower(trim($u->guid ?? ''));
            $map[$key] = [
                'name' => $u->name,
                'user_id' => $u->id,
                'whatsapp' => $u->whatsapp ?? null,
            ];
        }

        return $map;
    }
}


