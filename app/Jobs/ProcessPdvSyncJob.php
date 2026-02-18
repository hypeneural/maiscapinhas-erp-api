<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PdvSync;
use App\Support\Pdv\PdvDateTime;
use App\Support\Pdv\PdvStoreResolver;
use App\Support\Pdv\PdvUserResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProcessPdvSyncJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 120];

    /** @var array<int, string> */
    private array $runtimeRiskFlags = [];

    private ?bool $hasTurnoMappedUserColumn = null;
    private ?bool $hasItemMappedUserColumn = null;
    private ?bool $hasTurnoOperadorLoginColumn = null;
    private ?bool $hasTurnoResponsavelLoginColumn = null;
    private ?bool $hasItemVendedorLoginColumn = null;
    private ?bool $hasResumoVendedorLoginColumn = null;
    private ?bool $hasUserMappingsTable = null;
    private ?bool $hasPdvLojasTable = null;
    private ?bool $hasPdvUsuariosTable = null;
    private ?bool $hasPdvUsuariosLoginColumn = null;
    private ?bool $hasPdvMeiosPagamentoTable = null;
    private ?bool $hasPdvVendasLastSeenColumn = null;
    private ?bool $hasTurnoClosureUuidColumn = null;
    private ?bool $hasPagamentoUuidColumn = null;
    private ?bool $hasTurnoOperadorGuidColumn = null;
    private ?bool $hasItemVendedorGuidColumn = null;
    private ?bool $hasPdvLojasGuidColumn = null;
    private ?bool $hasPdvUsuariosGuidColumn = null;
    private const CANAL_HIPER_CAIXA = 'HIPER_CAIXA';
    private const CANAL_HIPER_LOJA = 'HIPER_LOJA';

    public function __construct(
        public int $pdvSyncId
    ) {
        $this->tries = max(1, (int) config('pdv.job_tries', 5));

        $backoff = config('pdv.job_backoff_seconds', [10, 30, 60, 120]);
        if (is_array($backoff)) {
            $normalized = array_values(array_filter(array_map(
                static fn(mixed $value): int => (int) $value,
                $backoff
            ), static fn(int $value): bool => $value >= 0));

            if ($normalized !== []) {
                $this->backoff = $normalized;
            }
        }
    }

    public function uniqueId(): string
    {
        return 'pdv-sync:' . $this->pdvSyncId;
    }

    public function handle(): void
    {
        $startedAt = microtime(true);
        $snapshotTurnosCount = 0;
        $snapshotVendasCount = 0;
        $sync = PdvSync::query()->with('payload')->find($this->pdvSyncId);
        if (!$sync) {
            return;
        }

        // Do not process the same sync twice if it is already final.
        if (in_array($sync->status, [PdvSync::STATUS_PROCESSED], true)) {
            return;
        }

        $lockKey = $this->storeLockKey($sync);
        $lock = Cache::lock($lockKey, (int) config('pdv.store_lock_seconds', 30));

        if (!$lock->get()) {
            $this->release(10);
            return;
        }

        try {
            DB::transaction(function () use ($sync, &$snapshotTurnosCount, &$snapshotVendasCount) {
                $sync->refresh()->load('payload');
                $sync->status = PdvSync::STATUS_PROCESSING;
                $sync->attempts = (int) $sync->attempts + 1;
                $sync->processing_started_at = now();
                $sync->save();

                $payload = $this->decodePayload($sync);
                $context = $this->resolveStoreContext($sync, $payload);
                $this->processMasterData($context['store_pdv_id'], $payload);
                $userMappings = $this->resolveUserMappings();
                $turnosCount = is_array(data_get($payload, 'turnos')) ? count((array) data_get($payload, 'turnos')) : 0;
                $vendasCount = is_array(data_get($payload, 'vendas')) ? count((array) data_get($payload, 'vendas')) : 0;

                $turnosAbertos = data_get($payload, 'turnos_abertos', []);
                $turnosFechados = data_get($payload, 'turnos_fechados', []);
                if (!empty($turnosAbertos) || !empty($turnosFechados)) {
                    $legacyTurnos = data_get($payload, 'turnos', []);
                    $mergedTurnos = array_merge(
                        is_array($legacyTurnos) ? $legacyTurnos : [],
                        is_array($turnosAbertos) ? $turnosAbertos : [],
                        is_array($turnosFechados) ? $turnosFechados : []
                    );
                    $payload['turnos'] = $mergedTurnos;
                }

                $this->processTurnos(
                    $sync,
                    $context['store_pdv_id'],
                    $context['store_id'],
                    $userMappings,
                    $payload
                );

                $snapshotTurnosCount = $this->processSnapshotTurnos(
                    $sync,
                    $context['store_pdv_id'],
                    $context['store_id'],
                    $userMappings,
                    $payload
                );

                $this->processVendas(
                    $sync,
                    $context['store_pdv_id'],
                    $context['store_id'],
                    $userMappings,
                    $payload
                );

                $snapshotVendasCount = $this->processSnapshotVendas(
                    $sync,
                    $context['store_pdv_id'],
                    $context['store_id'],
                    $userMappings,
                    $payload
                );

                $this->mergeRuntimeRiskFlags($sync);

                $sync->status = PdvSync::STATUS_PROCESSED;
                $sync->processed_at = now();
                $sync->last_error = null;
                $sync->save();

                Log::info('pdv.sync.process', [
                    'pdv_sync_id' => $sync->id,
                    'sync_id' => $sync->sync_id,
                    'schema_version' => $sync->schema_version,
                    'event_type' => $sync->event_type ?? PdvSync::EVENT_TYPE_SALES,
                    'request_id' => $sync->request_id,
                    'store_pdv_id' => $context['store_pdv_id'],
                    'store_id' => $context['store_id'],
                    'status' => 'processed',
                    'turnos_count' => $turnosCount,
                    'snapshot_turnos_count' => $snapshotTurnosCount,
                    'vendas_count' => $vendasCount,
                    'snapshot_vendas_count' => $snapshotVendasCount,
                ]);
            });
        } catch (Throwable $e) {
            $sync->status = PdvSync::STATUS_FAILED;
            $sync->last_error = Str::limit($e->getMessage(), 2000);
            $sync->save();

            Log::error('pdv.sync.process', [
                'pdv_sync_id' => $sync->id,
                'sync_id' => $sync->sync_id,
                'schema_version' => $sync->schema_version,
                'event_type' => $sync->event_type ?? PdvSync::EVENT_TYPE_SALES,
                'request_id' => $sync->request_id,
                'store_pdv_id' => $sync->store_pdv_id,
                'store_id' => $sync->store_id,
                'status' => 'failed',
                'message' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $lock->release();

            Log::info('pdv.sync.process', [
                'pdv_sync_id' => $sync->id,
                'sync_id' => $sync->sync_id,
                'schema_version' => $sync->schema_version,
                'event_type' => $sync->event_type ?? PdvSync::EVENT_TYPE_SALES,
                'request_id' => $sync->request_id,
                'status' => $sync->status,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(PdvSync $sync): array
    {
        $rawPayload = $sync->payload?->payload;
        if (!is_string($rawPayload) || trim($rawPayload) === '') {
            throw new RuntimeException('Missing RAW payload for PDV sync processing.');
        }

        $decoded = json_decode($rawPayload, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid RAW payload JSON.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{store_pdv_id:int, store_id:int|null}
     */
    private function resolveStoreContext(PdvSync $sync, array $payload): array
    {
        $storePdvId = (int) ($sync->store_pdv_id ?: (int) data_get($payload, 'store.id_ponto_venda', 0));

        // Fallback: Resolve store_pdv_id from GUID if missing (V5 Payload Support)
        if ($storePdvId <= 0) {
            $storeGuid = $this->asString(data_get($payload, 'store.LojaId') ?? data_get($payload, 'store.guid'));
            if ($storeGuid !== null) {
                $resolvedPdv = DB::table('pdv_lojas')
                    ->where('guid', $storeGuid)
                    ->value('id_ponto_venda');

                if ($resolvedPdv) {
                    $storePdvId = (int) $resolvedPdv;
                }
            }
        }

        if ($storePdvId <= 0) {
            throw new RuntimeException('Missing store.id_ponto_venda for PDV sync.');
        }

        // Persistir id_filial para rastreabilidade v4
        $storeIdFilial = $this->asInt(data_get($payload, 'store.id_filial'));
        if ($storeIdFilial !== null && $sync->store_id_filial === null) {
            $sync->store_id_filial = $storeIdFilial;
        }

        $storeId = $sync->store_id;
        if ($storeId !== null) {
            if ($sync->isDirty('store_id_filial')) {
                $sync->save();
            }
            return [
                'store_pdv_id' => $storePdvId,
                'store_id' => (int) $storeId,
            ];
        }

        $resolution = app(PdvStoreResolver::class)->resolve(
            $storePdvId,
            $this->asNormalizedLowerText(data_get($payload, 'store.alias')),
            $this->asNormalizedLowerText(data_get($payload, 'store.nome')),
            $this->normalizeCnpj(data_get($payload, 'store.cnpj')),
            $this->asString(data_get($payload, 'store.guid'))
        );
        $storeId = $resolution['store_id'] !== null ? (int) $resolution['store_id'] : null;

        // Self-healing: Update mapping with GUID if resolved by matched_by != guid
        $mappingId = $resolution['mapping_id'] ?? null;
        $payloadGuid = $this->asString(data_get($payload, 'store.guid'));

        if ($storeId !== null && $mappingId !== null && $payloadGuid !== null && $resolution['matched_by'] !== 'guid') {
            // We resolved by Alias/CNPJ, but we have a GUID in payload. Update the mapping.
            DB::table('pdv_store_mappings')
                ->where('id', $mappingId)
                ->whereNull('guid_loja') // Only update if empty to avoid overwriting conflicts (though unlikely)
                ->update(['guid_loja' => $payloadGuid]);
        }

        $resolutionRiskFlags = is_array($resolution['risk_flags'] ?? null)
            ? array_values(array_unique($resolution['risk_flags']))
            : [];
        if ($resolutionRiskFlags !== []) {
            $this->appendRiskFlags($sync, $resolutionRiskFlags);
        }

        if ($storeId === null) {
            Log::warning('pdv.sync.store_resolution_missing', [
                'pdv_sync_id' => $sync->id,
                'sync_id' => $sync->sync_id,
                'store_pdv_id' => $storePdvId,
                'status' => $resolution['status'] ?? null,
                'matched_by' => $resolution['matched_by'] ?? null,
                'candidate_store_ids' => $resolution['candidate_store_ids'] ?? [],
            ]);

            return [
                'store_pdv_id' => $storePdvId,
                'store_id' => null,
            ];
        }

        $sync->store_id = $storeId;
        $sync->save();

        return [
            'store_pdv_id' => $storePdvId,
            'store_id' => $storeId,
        ];
    }

    /**
     * @param array{
     *   by_id:array<int, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>,
     *   by_login:array<string, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>
     * } $userMappings
     * @param array<string, mixed> $payload
     */
    private function processTurnos(PdvSync $sync, int $storePdvId, ?int $storeId, array $userMappings, array $payload): void
    {
        $turnos = data_get($payload, 'turnos', []);
        if (!is_array($turnos) || $turnos === []) {
            return;
        }

        $now = now();
        $hasOperadorUserId = $this->supportsTurnoMappedUserId();
        $hasOperadorLogin = $this->supportsTurnoOperadorLoginColumn();
        $hasResponsavelLogin = $this->supportsTurnoResponsavelLoginColumn();
        $hasOperadorGuid = $this->supportsOperadorGuid();
        $hasClosureUuid = $this->supportsClosureUuid();
        $hasPagamentoUuid = $this->supportsPagamentoUuid();
        $turnoRows = [];
        $pagamentoRows = [];

        foreach ($turnos as $turno) {
            if (!is_array($turno)) {
                continue;
            }

            $idTurno = trim((string) (data_get($turno, 'id_turno') ?? data_get($turno, 'IdTurno') ?? ''));
            if ($idTurno === '') {
                Log::warning('Skipping turno without id_turno.', [
                    'pdv_sync_id' => $sync->id,
                    'sync_id' => $sync->sync_id,
                    'store_pdv_id' => $storePdvId,
                ]);
                continue;
            }

            $canal = $this->resolveTurnoCanal($sync, $storePdvId, data_get($turno, 'canal') ?? data_get($turno, 'Canal'));
            $operadorPdvId = $this->asInt($this->turnoGet($turno, 'operador.id_usuario'));
            $operadorLogin = $this->asString($this->turnoGet($turno, 'operador.login'));
            $responsavelLogin = $this->asString($this->turnoGet($turno, 'responsavel.login'));
            $operadorUserId = $hasOperadorUserId
                ? $this->resolveMappedUserId($storePdvId, $operadorPdvId, $operadorLogin, $userMappings, $this->asString($this->turnoGet($turno, 'operador.guid')))
                : null;

            $turnoRows[] = [
                'store_pdv_id' => $storePdvId,
                'store_id' => $storeId,
                'canal' => $canal,
                'id_turno' => $idTurno,
                'sequencial' => $this->asInt(data_get($turno, 'sequencial') ?? data_get($turno, 'Sequencial')),
                'fechado' => (bool) (data_get($turno, 'fechado') ?? data_get($turno, 'Fechado') ?? false),
                'data_hora_inicio' => $this->asDateTimeString(data_get($turno, 'data_hora_inicio') ?? data_get($turno, 'DataHoraInicio')),
                'data_hora_termino' => $this->asDateTimeString(data_get($turno, 'data_hora_termino') ?? data_get($turno, 'DataHoraTermino')),
                'duracao_minutos' => $this->asInt(data_get($turno, 'duracao_minutos') ?? data_get($turno, 'DuracaoMinutos')),
                'periodo' => $this->asString(data_get($turno, 'periodo') ?? data_get($turno, 'Periodo')),
                'operador_pdv_id' => $operadorPdvId,
                'operador_nome' => $this->asString($this->turnoGet($turno, 'operador.nome')),
                'responsavel_pdv_id' => $this->asInt($this->turnoGet($turno, 'responsavel.id_usuario')),
                'responsavel_nome' => $this->asString($this->turnoGet($turno, 'responsavel.nome')),
                'total_sistema' => $this->asDecimal($this->turnoGet($turno, 'totais_sistema.total'), 2),
                'qtd_vendas_sistema' => max(0, (int) $this->turnoGet($turno, 'totais_sistema.qtd_vendas', 0)),
                'qtd_vendas' => max(0, (int) (data_get($turno, 'qtd_vendas') ?? data_get($turno, 'QtdVendas') ?? 0)),
                'total_vendas' => $this->asDecimal(data_get($turno, 'total_vendas') ?? data_get($turno, 'TotalVendas') ?? 0, 2),
                'qtd_vendedores' => max(0, (int) (data_get($turno, 'qtd_vendedores') ?? data_get($turno, 'QtdVendedores') ?? 0)),
                'total_declarado' => $this->asDecimalNullable($this->turnoGet($turno, 'fechamento_declarado.total'), 2),
                'total_falta' => $this->asDecimalNullable($this->turnoGet($turno, 'falta_caixa.total'), 2),
                'last_sync_id' => $sync->sync_id,
                'last_window_to' => $sync->window_to?->toDateTimeString(),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($hasOperadorUserId) {
                $turnoRows[array_key_last($turnoRows)]['operador_user_id'] = $operadorUserId;
            }
            if ($hasOperadorLogin) {
                $turnoRows[array_key_last($turnoRows)]['operador_login'] = $operadorLogin;
            }
            if ($hasResponsavelLogin) {
                $turnoRows[array_key_last($turnoRows)]['responsavel_login'] = $responsavelLogin;
            }
            if ($hasOperadorGuid) {
                $lastKey = array_key_last($turnoRows);
                $turnoRows[$lastKey]['operador_guid'] = $this->asString($this->turnoGet($turno, 'operador.guid'));
                $turnoRows[$lastKey]['operador_hiper_id'] = $this->asInt($this->turnoGet($turno, 'operador.id_hiper'));
                $turnoRows[$lastKey]['responsavel_guid'] = $this->asString($this->turnoGet($turno, 'responsavel.guid'));
                $turnoRows[$lastKey]['responsavel_hiper_id'] = $this->asInt($this->turnoGet($turno, 'responsavel.id_hiper'));
            }
            if ($hasClosureUuid) {
                $lastKey = array_key_last($turnoRows);
                $turnoRows[$lastKey]['closure_uuid'] = $this->asString($this->turnoGet($turno, 'fechamento_declarado.Id'));
                $turnoRows[$lastKey]['data_hora_fechamento'] = $this->asDateTimeString($this->turnoGet($turno, 'fechamento_declarado.DataHora'));
                $turnoRows[$lastKey]['falta_uuid'] = $this->asString($this->turnoGet($turno, 'falta_caixa.Id'));
                $turnoRows[$lastKey]['sobra_uuid'] = $this->asString($this->turnoGet($turno, 'sobra_caixa.Id'));
                $turnoRows[$lastKey]['total_sobra'] = $this->asDecimalNullable($this->turnoGet($turno, 'sobra_caixa.total'), 2);
                $turnoRows[$lastKey]['tipo_operacao_fechamento'] = $this->asString($this->turnoGet($turno, 'fechamento_declarado.TipoDaOperacao'));
            }

            $declaradoUuid = $this->asString($this->turnoGet($turno, 'fechamento_declarado.Id'));
            $faltaUuid = $this->asString($this->turnoGet($turno, 'falta_caixa.Id'));
            $sobraUuid = $this->asString($this->turnoGet($turno, 'sobra_caixa.Id'));

            $pagamentoRows = array_merge(
                $pagamentoRows,
                $this->buildTurnoPagamentoRows(
                    $storePdvId,
                    $storeId,
                    $canal,
                    $idTurno,
                    'sistema',
                    $this->turnoGet($turno, 'totais_sistema.por_pagamento', []),
                    $sync,
                    $now,
                    $hasPagamentoUuid,
                    null
                ),
                $this->buildTurnoPagamentoRows(
                    $storePdvId,
                    $storeId,
                    $canal,
                    $idTurno,
                    'declarado',
                    $this->turnoGet($turno, 'fechamento_declarado.por_pagamento', []),
                    $sync,
                    $now,
                    $hasPagamentoUuid,
                    $declaradoUuid
                ),
                $this->buildTurnoPagamentoRows(
                    $storePdvId,
                    $storeId,
                    $canal,
                    $idTurno,
                    'falta',
                    $this->turnoGet($turno, 'falta_caixa.por_pagamento', []),
                    $sync,
                    $now,
                    $hasPagamentoUuid,
                    $faltaUuid
                ),
                $this->buildTurnoPagamentoRows(
                    $storePdvId,
                    $storeId,
                    $canal,
                    $idTurno,
                    'sobra',
                    $this->turnoGet($turno, 'sobra_caixa.por_pagamento', []),
                    $sync,
                    $now,
                    $hasPagamentoUuid,
                    $sobraUuid
                )
            );
        }

        $turnoUpdateColumns = [
            'store_id',
            'sequencial',
            'fechado',
            'data_hora_inicio',
            'data_hora_termino',
            'duracao_minutos',
            'periodo',
            'operador_pdv_id',
            'operador_nome',
            'responsavel_pdv_id',
            'responsavel_nome',
            'total_sistema',
            'qtd_vendas_sistema',
            'qtd_vendas',
            'total_vendas',
            'qtd_vendedores',
            'total_declarado',
            'total_falta',
            'last_sync_id',
            'last_window_to',
            'updated_at',
        ];
        if ($hasOperadorUserId) {
            $turnoUpdateColumns[] = 'operador_user_id';
        }
        if ($hasOperadorLogin) {
            $turnoUpdateColumns[] = 'operador_login';
        }
        if ($hasResponsavelLogin) {
            $turnoUpdateColumns[] = 'responsavel_login';
        }
        if ($hasOperadorGuid) {
            array_push($turnoUpdateColumns, 'operador_guid', 'operador_hiper_id', 'responsavel_guid', 'responsavel_hiper_id');
        }
        if ($hasClosureUuid) {
            array_push($turnoUpdateColumns, 'closure_uuid', 'data_hora_fechamento', 'falta_uuid', 'sobra_uuid', 'total_sobra', 'tipo_operacao_fechamento');
        }

        $this->upsertRows(
            'pdv_turnos',
            $turnoRows,
            ['store_pdv_id', 'canal', 'id_turno'],
            $turnoUpdateColumns
        );

        $this->upsertRows(
            'pdv_turno_pagamentos',
            $pagamentoRows,
            ['store_pdv_id', 'canal', 'id_turno', 'tipo', 'id_finalizador'],
            $this->buildPagamentoUpdateColumns($hasPagamentoUuid)
        );
    }

    /**
     * @param array{
     *   by_id:array<int, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>,
     *   by_login:array<string, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>
     * } $userMappings
     * @param array<string, mixed> $payload
     */
    private function processVendas(PdvSync $sync, int $storePdvId, ?int $storeId, array $userMappings, array $payload): void
    {
        $vendas = data_get($payload, 'vendas', []);
        if (!is_array($vendas) || $vendas === []) {
            return;
        }

        $now = now();
        $hasVendedorUserId = $this->supportsItemMappedUserId();
        $hasVendedorLogin = $this->supportsItemVendedorLoginColumn();
        $hasVendedorGuid = $this->supportsVendedorGuid();
        $vendaRows = [];
        $itemRowsByLineId = [];
        $itemRowsFallback = [];
        $pagamentoRowsByLineId = [];
        $pagamentoRowsFallback = [];

        foreach ($vendas as $venda) {
            if (!is_array($venda)) {
                continue;
            }

            $idOperacao = (int) data_get($venda, 'id_operacao', 0);
            // Fallback for V5 PascalCase
            if ($idOperacao <= 0) {
                $idOperacao = (int) data_get($venda, 'SaleId', 0);
            }

            if ($idOperacao <= 0) {
                Log::warning('Skipping venda without id_operacao.', [
                    'pdv_sync_id' => $sync->id,
                    'sync_id' => $sync->sync_id,
                    'store_pdv_id' => $storePdvId,
                ]);
                continue;
            }

            $idTurno = $this->asString(data_get($venda, 'id_turno'));
            if ($idTurno === null) {
                $idTurno = $this->asString(data_get($venda, 'TurnoId')); // V5
            }
            $turnoSeq = $this->asInt(data_get($venda, 'turno_seq') ?? data_get($venda, 'TurnoSeq'));

            $canal = $this->resolveVendaCanal(
                $sync,
                $storePdvId,
                $idOperacao,
                data_get($venda, 'canal') ?? data_get($venda, 'Canal') // V5 fallback
            );

            // V5 Data Extraction
            $erpOperacaoUuid = $this->asString(data_get($venda, 'ErpOperacaoUuid'));
            $erpLojaUuid = $this->asString(data_get($venda, 'ErpLojaUuid') ?? data_get($payload, 'store.LojaId'));

            $fiscal = data_get($venda, 'Fiscal', []);
            $nfceChave = $this->asString(data_get($fiscal, 'NfceChave'));
            $nfceProtocolo = $this->asString(data_get($fiscal, 'NfceProtocolo'));
            $nfceNumero = $this->asString(data_get($fiscal, 'NfceNumero'));
            $nfceSerie = $this->asString(data_get($fiscal, 'NfceSerie'));
            $nfceModelo = $this->asString(data_get($fiscal, 'NfceModelo'));
            $nfeChave = $this->asString(data_get($fiscal, 'NfeChave'));

            $clienteCpf = $this->asString(data_get($venda, 'ClientCpf'));
            $signatureHash = $this->asString(data_get($venda, 'Signature.HashValue'));

            // V5 Date/Total fallbacks
            $dataHora = $this->asDateTimeString(data_get($venda, 'data_hora') ?? data_get($venda, 'DateTime'));
            $total = $this->asDecimal(data_get($venda, 'total') ?? data_get($venda, 'TotalAmount') ?? data_get($venda, 'Total'), 2);
            $status = $this->asString(data_get($venda, 'status') ?? data_get($venda, 'Status'));

            $vendaRows[] = [
                'store_pdv_id' => $storePdvId,
                'store_id' => $storeId,
                'canal' => $canal,
                'id_operacao' => $idOperacao,
                'id_turno' => $idTurno,
                'turno_seq' => $turnoSeq,
                'data_hora' => $dataHora,
                'total' => $total,
                'status' => $status,
                'sync_id' => $sync->sync_id,
                'last_window_to' => $sync->window_to?->toDateTimeString(),
                'created_at' => $now,
                'updated_at' => $now,
                // V5 Columns
                'erp_operacao_uuid' => $erpOperacaoUuid,
                'erp_loja_uuid' => $erpLojaUuid,
                'nfce_chave' => $nfceChave,
                'nfce_protocolo' => $nfceProtocolo,
                'nfce_numero' => $nfceNumero,
                'nfce_serie' => $nfceSerie,
                'nfce_modelo' => $nfceModelo,
                'nfe_chave' => $nfeChave,
                'cliente_cpf' => $clienteCpf,
                'signature_hash' => $signatureHash,
            ];

            $itemOccurrences = [];
            $itens = data_get($venda, 'itens') ?? data_get($venda, 'Itens', []);
            if (is_array($itens)) {
                foreach ($itens as $index => $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $idProduto = $this->asInt(data_get($item, 'id_produto') ?? data_get($item, 'Codigo'));
                    $codigoBarras = $this->asString(data_get($item, 'codigo_barras') ?? data_get($item, 'CodigoBarras'));
                    $nomeProduto = $this->asString(data_get($item, 'nome') ?? data_get($item, 'Nome'));
                    $qtd = $this->asDecimal(data_get($item, 'qtd') ?? data_get($item, 'Quantidade') ?? 1, 3);
                    $precoUnit = $this->asDecimal(data_get($item, 'preco_unit') ?? data_get($item, 'ValorUnitario'), 2);
                    $totalItem = $this->asDecimal(data_get($item, 'total') ?? data_get($item, 'ValorTotal'), 2);
                    $desconto = $this->asDecimal(data_get($item, 'desconto') ?? data_get($item, 'Desconto'), 2);

                    // V5 Vendedor Normalization
                    $vendedorObj = data_get($item, 'vendedor') ?? data_get($item, 'Vendedor', []);
                    $vendedorPdvId = $this->asInt(data_get($vendedorObj, 'id_usuario') ?? data_get($vendedorObj, 'IdUsuarioHiperOnline'));
                    $vendedorLogin = $this->asString(data_get($vendedorObj, 'login') ?? data_get($vendedorObj, 'Login') ?? data_get($vendedorObj, 'UserName'));
                    $vendedorNome = $this->asString(data_get($vendedorObj, 'nome') ?? data_get($vendedorObj, 'Nome'));
                    $vendedorGuid = $this->asString(data_get($vendedorObj, 'guid') ?? data_get($vendedorObj, 'Id') ?? data_get($item, 'SellerId'));

                    $vendedorUserId = $hasVendedorUserId
                        ? $this->resolveMappedUserId($storePdvId, $vendedorPdvId, $vendedorLogin, $userMappings, $vendedorGuid)
                        : null;

                    $lineId = $this->asInt(data_get($item, 'line_id'));
                    $lineId = $lineId !== null && $lineId > 0 ? $lineId : null;
                    $lineNo = $this->resolveLineNumber($item, $index);
                    $lineNoProvided = (int) data_get($item, 'line_no', 0);
                    $fingerprint = $this->itemFingerprint([
                        'id_produto' => $idProduto,
                        'codigo_barras' => $codigoBarras,
                        'nome_produto' => $nomeProduto,
                        'qtd' => $qtd,
                        'preco_unit' => $precoUnit,
                        'total' => $totalItem,
                        'desconto' => $desconto,
                        'vendedor_pdv_id' => $vendedorPdvId,
                        'vendedor_nome' => $vendedorNome,
                    ]);
                    $rowHash = $lineId !== null
                        ? $this->childRowHashByLineId('item', $storePdvId, $lineId)
                        : $this->childRowHash(
                            'item',
                            $storePdvId,
                            $idOperacao,
                            $lineNoProvided > 0 ? $lineNo : null,
                            $fingerprint,
                            $this->nextOccurrence($itemOccurrences, $fingerprint)
                        );

                    $itemRow = [
                        'store_pdv_id' => $storePdvId,
                        'store_id' => $storeId,
                        'canal' => $canal,
                        'id_operacao' => $idOperacao,
                        'line_id' => $lineId,
                        'line_no' => $lineNo,
                        'row_hash' => $rowHash,
                        'id_produto' => $idProduto,
                        'codigo_barras' => $codigoBarras,
                        'nome_produto' => $nomeProduto,
                        'qtd' => $qtd,
                        'preco_unit' => $precoUnit,
                        'total' => $totalItem,
                        'desconto' => $desconto,
                        'vendedor_pdv_id' => $vendedorPdvId,
                        'vendedor_nome' => $vendedorNome,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if ($hasVendedorUserId) {
                        $itemRow['vendedor_user_id'] = $vendedorUserId;
                    }
                    if ($hasVendedorLogin) {
                        $itemRow['vendedor_login'] = $vendedorLogin;
                    }
                    if ($hasVendedorGuid) {
                        $itemRow['vendedor_guid'] = $vendedorGuid;
                        $itemRow['vendedor_hiper_id'] = $this->asInt(data_get($vendedorObj, 'id_hiper') ?? data_get($vendedorObj, 'IdUsuarioHiperOnline'));
                    }

                    if ($lineId !== null) {
                        $itemRowsByLineId[] = $itemRow;
                    } else {
                        $itemRowsFallback[] = $itemRow;
                    }
                }
            }

            $paymentOccurrences = [];
            $pagamentos = data_get($venda, 'pagamentos') ?? data_get($venda, 'Pagamentos', []);
            if (is_array($pagamentos)) {
                foreach ($pagamentos as $index => $pagamento) {
                    if (!is_array($pagamento)) {
                        continue;
                    }

                    $idFinalizador = max(0, (int) (data_get($pagamento, 'id_finalizador') ?? data_get($pagamento, 'Codigo') ?? 0));
                    $meioPagamento = $this->asString(data_get($pagamento, 'meio') ?? data_get($pagamento, 'Descricao'));
                    $valor = $this->asDecimal(data_get($pagamento, 'valor') ?? data_get($pagamento, 'Valor'), 2);
                    $troco = $this->asDecimal(data_get($pagamento, 'troco') ?? data_get($pagamento, 'Troco'), 2);
                    $parcelas = max(1, (int) (data_get($pagamento, 'parcelas') ?? data_get($pagamento, 'Parcelas') ?? 1));
                    $lineId = $this->asInt(data_get($pagamento, 'line_id'));
                    $lineId = $lineId !== null && $lineId > 0 ? $lineId : null;
                    $lineNo = $this->resolveLineNumber($pagamento, $index);
                    $lineNoProvided = (int) data_get($pagamento, 'line_no', 0);
                    $fingerprint = $this->paymentFingerprint([
                        'id_finalizador' => $idFinalizador,
                        'meio_pagamento' => $meioPagamento,
                        'valor' => $valor,
                        'troco' => $troco,
                        'parcelas' => $parcelas,
                    ]);
                    $rowHash = $lineId !== null
                        ? $this->childRowHashByLineId('payment', $storePdvId, $lineId)
                        : $this->childRowHash(
                            'payment',
                            $storePdvId,
                            $idOperacao,
                            $lineNoProvided > 0 ? $lineNo : null,
                            $fingerprint,
                            $this->nextOccurrence($paymentOccurrences, $fingerprint)
                        );

                    $pagamentoRow = [
                        'store_pdv_id' => $storePdvId,
                        'store_id' => $storeId,
                        'canal' => $canal,
                        'id_operacao' => $idOperacao,
                        'line_id' => $lineId,
                        'line_no' => $lineNo,
                        'row_hash' => $rowHash,
                        'id_finalizador' => $idFinalizador,
                        'meio_pagamento' => $meioPagamento,
                        'valor' => $valor,
                        'troco' => $troco,
                        'parcelas' => $parcelas,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if ($lineId !== null) {
                        $pagamentoRowsByLineId[] = $pagamentoRow;
                    } else {
                        $pagamentoRowsFallback[] = $pagamentoRow;
                    }
                }
            }
        }

        $this->upsertRows(
            'pdv_vendas',
            $vendaRows,
            ['store_pdv_id', 'canal', 'id_operacao'],
            [
                'store_id',
                'id_turno',
                'turno_seq',
                'data_hora',
                'total',
                'status',
                'sync_id',
                'last_window_to',
                'updated_at',
                'erp_operacao_uuid',
                'erp_loja_uuid',
                'nfce_chave',
                'nfce_protocolo',
                'nfce_numero',
                'nfce_serie',
                'nfce_modelo',
                'nfe_chave',
                'cliente_cpf',
                'signature_hash'
            ]
        );

        $itemUpdateColumnsByLineId = [
            'canal',
            'line_id',
            'store_id',
            'id_operacao',
            'line_no',
            'row_hash',
            'id_produto',
            'codigo_barras',
            'nome_produto',
            'qtd',
            'preco_unit',
            'total',
            'desconto',
            'vendedor_pdv_id',
            'vendedor_nome',
            'updated_at',
        ];
        $itemUpdateColumnsFallback = [
            'canal',
            'store_id',
            'line_id',
            'line_no',
            'id_produto',
            'codigo_barras',
            'nome_produto',
            'qtd',
            'preco_unit',
            'total',
            'desconto',
            'vendedor_pdv_id',
            'vendedor_nome',
            'updated_at',
        ];
        if ($hasVendedorUserId) {
            $itemUpdateColumnsByLineId[] = 'vendedor_user_id';
            $itemUpdateColumnsFallback[] = 'vendedor_user_id';
        }
        if ($hasVendedorLogin) {
            $itemUpdateColumnsByLineId[] = 'vendedor_login';
            $itemUpdateColumnsFallback[] = 'vendedor_login';
        }
        if ($hasVendedorGuid) {
            $itemUpdateColumnsByLineId[] = 'vendedor_guid';
            $itemUpdateColumnsByLineId[] = 'vendedor_hiper_id';
            $itemUpdateColumnsFallback[] = 'vendedor_guid';
            $itemUpdateColumnsFallback[] = 'vendedor_hiper_id';
        }

        $this->upsertRows(
            'pdv_venda_itens',
            $itemRowsByLineId,
            ['store_pdv_id', 'canal', 'line_id'],
            $itemUpdateColumnsByLineId
        );

        $this->upsertRows(
            'pdv_venda_itens',
            $itemRowsFallback,
            ['store_pdv_id', 'canal', 'id_operacao', 'row_hash'],
            $itemUpdateColumnsFallback
        );

        $this->upsertRows(
            'pdv_venda_pagamentos',
            $pagamentoRowsByLineId,
            ['store_pdv_id', 'canal', 'line_id'],
            [
                'canal',
                'line_id',
                'store_id',
                'id_operacao',
                'line_no',
                'row_hash',
                'id_finalizador',
                'meio_pagamento',
                'valor',
                'troco',
                'parcelas',
                'updated_at',
            ]
        );

        $this->upsertRows(
            'pdv_venda_pagamentos',
            $pagamentoRowsFallback,
            ['store_pdv_id', 'canal', 'id_operacao', 'row_hash'],
            [
                'canal',
                'store_id',
                'line_id',
                'line_no',
                'id_finalizador',
                'meio_pagamento',
                'valor',
                'troco',
                'parcelas',
                'updated_at',
            ]
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function processSnapshotVendas(
        PdvSync $sync,
        int $storePdvId,
        ?int $storeId,
        array $userMappings,
        array $payload
    ): int {
        $snapshotVendas = data_get($payload, 'snapshot_vendas', []);
        if (!is_array($snapshotVendas) || $snapshotVendas === []) {
            return 0;
        }

        // V5 Detection: If payload contains V5 specific fields (like Status, or keys are PascalCase with Itens), 
        // OR simply checking if the first item has 'Itens' (V5) vs 'qtd_itens' (summary).
        // V5 snapshots are full SaleDetail objects.
        $first = reset($snapshotVendas);
        $isV5Snapshot = is_array($first) && (
            isset($first['Itens']) || isset($first['Status']) || isset($first['SaleId'])
        );

        if ($isV5Snapshot) {
            // Process as full sales, upserting into pdv_vendas
            // We reuse processVendas logic by wrapping the snapshot list
            // Note: We might want to separate this into a dedicated method if logic diverges, 
            // but for now reusing processVendas is the goal to ensure full data retention.
            $this->processVendas(
                $sync,
                $storePdvId,
                $storeId,
                $userMappings,
                ['vendas' => $snapshotVendas] // Wrap as 'vendas' for the method
            );
            return count($snapshotVendas);
        }

        // Legacy: Process as summary (pdv_vendas_resumo)
        $now = now();
        $hasResumoVendedorLogin = $this->supportsResumoVendedorLoginColumn();
        $rows = [];
        foreach ($snapshotVendas as $index => $snapshotVenda) {
            if (!is_array($snapshotVenda)) {
                $this->markRuntimeRiskFlag('snapshot_venda_malformed');
                Log::warning('Skipping malformed snapshot_venda entry.', [
                    'pdv_sync_id' => $sync->id,
                    'sync_id' => $sync->sync_id,
                    'store_pdv_id' => $storePdvId,
                    'snapshot_index' => $index,
                ]);
                continue;
            }

            $idOperacao = (int) data_get($snapshotVenda, 'id_operacao', 0);
            if ($idOperacao <= 0) {
                $this->markRuntimeRiskFlag('snapshot_venda_malformed');
                Log::warning('Skipping snapshot_venda without id_operacao.', [
                    'pdv_sync_id' => $sync->id,
                    'sync_id' => $sync->sync_id,
                    'store_pdv_id' => $storePdvId,
                    'snapshot_index' => $index,
                ]);
                continue;
            }

            $canal = $this->resolveVendaCanal(
                $sync,
                $storePdvId,
                $idOperacao,
                data_get($snapshotVenda, 'canal')
            );

            $rows[] = [
                'store_pdv_id' => $storePdvId,
                'store_id' => $storeId,
                'canal' => $canal,
                'id_operacao' => $idOperacao,
                'data_hora_inicio' => $this->asDateTimeString(data_get($snapshotVenda, 'data_hora_inicio')),
                'data_hora_termino' => $this->asDateTimeString(data_get($snapshotVenda, 'data_hora_termino')),
                'duracao_segundos' => $this->asInt(data_get($snapshotVenda, 'duracao_segundos')),
                'id_turno' => $this->asString(data_get($snapshotVenda, 'id_turno')),
                'turno_seq' => $this->asInt(data_get($snapshotVenda, 'turno_seq')),
                'vendedor_pdv_id' => $this->asInt(data_get($snapshotVenda, 'vendedor.id_usuario') ?? data_get($snapshotVenda, 'Vendedor.IdUsuarioHiperOnline')),
                'vendedor_nome' => $this->asString(data_get($snapshotVenda, 'vendedor.nome') ?? data_get($snapshotVenda, 'Vendedor.Nome')),
                'qtd_itens' => max(0, (int) data_get($snapshotVenda, 'qtd_itens', 0)),
                'total_itens' => $this->asDecimal(data_get($snapshotVenda, 'total_itens', 0), 2),
                'last_sync_id' => $sync->sync_id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($hasResumoVendedorLogin) {
                $rows[array_key_last($rows)]['vendedor_login'] = $this->asString(data_get($snapshotVenda, 'vendedor.login') ?? data_get($snapshotVenda, 'Vendedor.Login') ?? data_get($snapshotVenda, 'Vendedor.UserName'));
            }
        }

        $updateColumns = [
            'store_id',
            'data_hora_inicio',
            'data_hora_termino',
            'duracao_segundos',
            'id_turno',
            'turno_seq',
            'vendedor_pdv_id',
            'vendedor_nome',
            'qtd_itens',
            'total_itens',
            'last_sync_id',
            'updated_at',
        ];
        if ($hasResumoVendedorLogin) {
            $updateColumns[] = 'vendedor_login';
        }

        $this->upsertRows(
            'pdv_vendas_resumo',
            $rows,
            ['store_pdv_id', 'canal', 'id_operacao'],
            $updateColumns
        );

        if ($this->supportsPdvVendasLastSeenColumn()) {
            $this->touchLastSeenInSnapshot($rows, $now);
        }

        return count($rows);
    }

    /**
     * @param array{
     *   by_id:array<int, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>,
     *   by_login:array<string, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>
     * } $userMappings
     * @param array<string, mixed> $payload
     */
    private function processSnapshotTurnos(
        PdvSync $sync,
        int $storePdvId,
        ?int $storeId,
        array $userMappings,
        array $payload
    ): int {
        $snapshotTurnos = data_get($payload, 'snapshot_turnos', []);
        if (!is_array($snapshotTurnos) || $snapshotTurnos === []) {
            return 0;
        }

        $now = now();
        $hasOperadorUserId = $this->supportsTurnoMappedUserId();
        $hasOperadorLogin = $this->supportsTurnoOperadorLoginColumn();
        $hasResponsavelLogin = $this->supportsTurnoResponsavelLoginColumn();
        $hasOperadorGuid = $this->supportsOperadorGuid();
        $rows = [];

        foreach ($snapshotTurnos as $index => $snapshotTurno) {
            if (!is_array($snapshotTurno)) {
                $this->markRuntimeRiskFlag('snapshot_turno_malformed');
                Log::warning('Skipping malformed snapshot_turno entry.', [
                    'pdv_sync_id' => $sync->id,
                    'sync_id' => $sync->sync_id,
                    'store_pdv_id' => $storePdvId,
                    'snapshot_index' => $index,
                ]);
                continue;
            }

            $idTurno = trim((string) data_get($snapshotTurno, 'id_turno', ''));
            if ($idTurno === '') {
                $this->markRuntimeRiskFlag('snapshot_turno_malformed');
                Log::warning('Skipping snapshot_turno without id_turno.', [
                    'pdv_sync_id' => $sync->id,
                    'sync_id' => $sync->sync_id,
                    'store_pdv_id' => $storePdvId,
                    'snapshot_index' => $index,
                ]);
                continue;
            }

            $canal = $this->resolveTurnoCanal($sync, $storePdvId, data_get($snapshotTurno, 'canal'));

            $operadorObj = data_get($snapshotTurno, 'operador') ?? data_get($snapshotTurno, 'Operador', []);
            $responsavelObj = data_get($snapshotTurno, 'responsavel') ?? data_get($snapshotTurno, 'Responsavel', []);

            $operadorPdvId = $this->asInt(data_get($operadorObj, 'id_usuario') ?? data_get($operadorObj, 'IdUsuarioHiperOnline') ?? data_get($operadorObj, 'Codigo'));
            $operadorLogin = $this->asString(data_get($operadorObj, 'login') ?? data_get($operadorObj, 'Login') ?? data_get($operadorObj, 'UserName'));
            $operadorGuid = $this->asString(data_get($operadorObj, 'guid') ?? data_get($operadorObj, 'Id'));

            $responsavelLogin = $this->asString(data_get($responsavelObj, 'login') ?? data_get($responsavelObj, 'Login') ?? data_get($responsavelObj, 'UserName'));

            $operadorUserId = $hasOperadorUserId
                ? $this->resolveMappedUserId($storePdvId, $operadorPdvId, $operadorLogin, $userMappings, $operadorGuid)
                : null;

            $rows[] = [
                'store_pdv_id' => $storePdvId,
                'store_id' => $storeId,
                'canal' => $canal,
                'id_turno' => $idTurno,
                'sequencial' => $this->asInt(data_get($snapshotTurno, 'sequencial')),
                'fechado' => (bool) data_get($snapshotTurno, 'fechado', false),
                'data_hora_inicio' => $this->asDateTimeString(data_get($snapshotTurno, 'data_hora_inicio')),
                'data_hora_termino' => $this->asDateTimeString(data_get($snapshotTurno, 'data_hora_termino')),
                'duracao_minutos' => $this->asInt(data_get($snapshotTurno, 'duracao_minutos')),
                'periodo' => $this->asString(data_get($snapshotTurno, 'periodo')),
                'operador_pdv_id' => $operadorPdvId,
                'operador_nome' => $this->asString(data_get($snapshotTurno, 'operador.nome')),
                'responsavel_pdv_id' => $this->asInt(data_get($snapshotTurno, 'responsavel.id_usuario')),
                'responsavel_nome' => $this->asString(data_get($snapshotTurno, 'responsavel.nome')),
                'total_sistema' => $this->asDecimal(data_get($snapshotTurno, 'total_vendas', 0), 2),
                'qtd_vendas_sistema' => max(0, (int) data_get($snapshotTurno, 'qtd_vendas', 0)),
                'qtd_vendas' => max(0, (int) data_get($snapshotTurno, 'qtd_vendas', 0)),
                'total_vendas' => $this->asDecimal(data_get($snapshotTurno, 'total_vendas', 0), 2),
                'qtd_vendedores' => max(0, (int) data_get($snapshotTurno, 'qtd_vendedores', 0)),
                'last_sync_id' => $sync->sync_id,
                'last_window_to' => $sync->window_to?->toDateTimeString(),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($hasOperadorUserId) {
                $rows[array_key_last($rows)]['operador_user_id'] = $operadorUserId;
            }
            if ($hasOperadorLogin) {
                $rows[array_key_last($rows)]['operador_login'] = $operadorLogin;
            }
            if ($hasResponsavelLogin) {
                $rows[array_key_last($rows)]['responsavel_login'] = $responsavelLogin;
            }
            if ($hasOperadorGuid) {
                $lastKey = array_key_last($rows);
                $rows[$lastKey]['operador_guid'] = $operadorGuid;
                $rows[$lastKey]['operador_hiper_id'] = $this->asInt(data_get($operadorObj, 'id_hiper') ?? data_get($operadorObj, 'IdUsuarioHiperOnline'));
                $rows[$lastKey]['responsavel_guid'] = $this->asString(data_get($responsavelObj, 'guid') ?? data_get($responsavelObj, 'Id'));
                $rows[$lastKey]['responsavel_hiper_id'] = $this->asInt(data_get($responsavelObj, 'id_hiper') ?? data_get($responsavelObj, 'IdUsuarioHiperOnline'));
            }
        }

        $updateColumns = [
            'store_id',
            'sequencial',
            'fechado',
            'data_hora_inicio',
            'data_hora_termino',
            'duracao_minutos',
            'periodo',
            'operador_pdv_id',
            'operador_nome',
            'responsavel_pdv_id',
            'responsavel_nome',
            'total_sistema',
            'qtd_vendas_sistema',
            'qtd_vendas',
            'total_vendas',
            'qtd_vendedores',
            'last_sync_id',
            'last_window_to',
            'updated_at',
        ];
        if ($hasOperadorUserId) {
            $updateColumns[] = 'operador_user_id';
        }
        if ($hasOperadorLogin) {
            $updateColumns[] = 'operador_login';
        }
        if ($hasResponsavelLogin) {
            $updateColumns[] = 'responsavel_login';
        }
        if ($hasOperadorGuid) {
            array_push($updateColumns, 'operador_guid', 'operador_hiper_id', 'responsavel_guid', 'responsavel_hiper_id');
        }

        $this->upsertRows(
            'pdv_turnos',
            $rows,
            ['store_pdv_id', 'canal', 'id_turno'],
            $updateColumns
        );

        return count($rows);
    }

    /**
     * @param mixed $values
     * @return array<int, array<string, mixed>>
     */
    private function buildTurnoPagamentoRows(
        int $storePdvId,
        ?int $storeId,
        string $canal,
        string $idTurno,
        string $tipo,
        mixed $values,
        PdvSync $sync,
        mixed $now,
        bool $hasPagamentoUuid = false,
        ?string $operacaoUuid = null
    ): array {
        if (!is_array($values) || $values === []) {
            return [];
        }

        $rows = [];
        foreach ($values as $item) {
            if (!is_array($item)) {
                continue;
            }

            $row = [
                'store_pdv_id' => $storePdvId,
                'store_id' => $storeId,
                'canal' => $canal,
                'id_turno' => $idTurno,
                'tipo' => $tipo,
                'id_finalizador' => max(0, (int) data_get($item, 'id_finalizador', 0)),
                'meio_pagamento' => $this->asString(data_get($item, 'meio')),
                'total' => $this->asDecimal(data_get($item, 'total'), 2),
                'qtd_vendas' => max(0, (int) data_get($item, 'qtd_vendas', 0)),
                'last_sync_id' => $sync->sync_id,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($hasPagamentoUuid) {
                $row['pagamento_uuid'] = $this->asString(data_get($item, 'Id'));
                $row['operacao_uuid'] = $operacaoUuid;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Read a turno key trying snake_case first, then PascalCase fallback.
     *
     * The RAW payload from the Python agent uses PascalCase aliases
     * (e.g. TotaisSistema, FechamentoDeclarado) but processTurnos()
     * expects snake_case keys. This helper resolves both formats.
     *
     * @param array<string, mixed> $turno
     */
    private function turnoGet(array $turno, string $snakeKey, mixed $default = null): mixed
    {
        static $rootMap = [
        'totais_sistema' => 'TotaisSistema',
        'fechamento_declarado' => 'FechamentoDeclarado',
        'falta_caixa' => 'FaltaCaixa',
        'sobra_caixa' => 'SobraCaixa',
        'operador' => 'Operador',
        'responsavel' => 'Responsavel',
        ];

        // Inner-field aliases: snake_case child key → PascalCase alias
        static $childMap = [
        'id_usuario' => 'Codigo',
        'nome' => 'Nome',
        'login' => 'Login',
        'guid' => 'Id',
        'id_hiper' => 'IdUsuarioHiperOnline',
        ];

        // 1. Try snake_case (normalized payload)
        $value = data_get($turno, $snakeKey);
        if ($value !== null) {
            return $value;
        }

        // 2. Try PascalCase root + original child (RAW payload)
        $parts = explode('.', $snakeKey, 2);
        $rootKey = $parts[0];
        $childKey = $parts[1] ?? null;

        if (!isset($rootMap[$rootKey])) {
            return $default;
        }

        $pascalRoot = $rootMap[$rootKey];

        if ($childKey === null) {
            // Entire sub-object requested
            return data_get($turno, $pascalRoot, $default);
        }

        // Try PascalCase root + original child key (e.g. TotaisSistema.total)
        $value = data_get($turno, $pascalRoot . '.' . $childKey);
        if ($value !== null) {
            return $value;
        }

        // Try PascalCase root + PascalCase child (e.g. Operador.Nome)
        if (isset($childMap[$childKey])) {
            $value = data_get($turno, $pascalRoot . '.' . $childMap[$childKey]);
            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $uniqueBy
     * @param array<int, string> $updateColumns
     */
    private function upsertRows(
        string $table,
        array $rows,
        array $uniqueBy,
        array $updateColumns,
        int $chunkSize = 500
    ): void {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            DB::table($table)->upsert($chunk, $uniqueBy, $updateColumns);
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function resolveLineNumber(array $item, int $index): int
    {
        $lineNo = (int) data_get($item, 'line_no', 0);

        return $lineNo > 0 ? $lineNo : ($index + 1);
    }

    /**
     * @param array<string, int> $occurrences
     */
    private function nextOccurrence(array &$occurrences, string $fingerprint): int
    {
        $occurrences[$fingerprint] = ($occurrences[$fingerprint] ?? 0) + 1;

        return $occurrences[$fingerprint];
    }

    /**
     * @param array<string, mixed> $itemData
     */
    private function itemFingerprint(array $itemData): string
    {
        return hash('sha256', $this->stableJsonEncode($itemData));
    }

    /**
     * @param array<string, mixed> $paymentData
     */
    private function paymentFingerprint(array $paymentData): string
    {
        return hash('sha256', $this->stableJsonEncode($paymentData));
    }

    private function childRowHash(
        string $kind,
        int $storePdvId,
        int $idOperacao,
        ?int $lineNo,
        string $fingerprint,
        int $occurrence
    ): string {
        $lineMarker = $lineNo !== null ? 'line:' . $lineNo : 'fp:' . $fingerprint . ':occ:' . $occurrence;

        return hash('sha256', implode('|', [
            'pdv',
            $kind,
            $storePdvId,
            $idOperacao,
            $lineMarker,
        ]));
    }

    private function childRowHashByLineId(string $kind, int $storePdvId, int $lineId): string
    {
        return hash('sha256', implode('|', [
            'pdv',
            $kind,
            $storePdvId,
            'line_id',
            $lineId,
        ]));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stableJsonEncode(array $data): string
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($encoded)) {
            return $encoded;
        }

        return serialize($data);
    }

    private function asDateTimeString(mixed $value): ?string
    {
        $parsed = PdvDateTime::parseToUtc($value);

        return $parsed?->toDateTimeString();
    }

    private function asDecimal(mixed $value, int $scale): string
    {
        return number_format((float) $value, $scale, '.', '');
    }

    private function asDecimalNullable(mixed $value, int $scale): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->asDecimal($value, $scale);
    }

    private function asInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function asString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $str = trim((string) $value);

        return $str === '' ? null : $str;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function processMasterData(int $storePdvId, array $payload): void
    {
        $now = now();

        if ($this->supportsPdvLojasTable()) {
            $hasPdvLojasGuid = $this->supportsPdvLojasGuid();
            $storeNome = $this->asString(data_get($payload, 'store.nome'));
            $storeAlias = $this->asString(data_get($payload, 'store.alias'));
            $storeGuid = $hasPdvLojasGuid ? $this->asString(data_get($payload, 'store.guid')) : null;
            $storeHiperId = $hasPdvLojasGuid ? $this->asInt(data_get($payload, 'store.id_hiper')) : null;

            $existingStore = DB::table('pdv_lojas')
                ->where('id_ponto_venda', $storePdvId)
                ->first(['id', 'nome_padronizado']);

            if ($existingStore) {
                $updates = [
                    'nome_hiper' => $storeNome,
                    'alias' => $storeAlias,
                    'updated_at' => $now,
                ];

                if ($hasPdvLojasGuid) {
                    $updates['guid'] = $storeGuid;
                    $updates['id_hiper'] = $storeHiperId;
                }

                DB::table('pdv_lojas')
                    ->where('id_ponto_venda', $storePdvId)
                    ->update($updates);
            } else {
                $insert = [
                    'id_ponto_venda' => $storePdvId,
                    'nome_padronizado' => $storeNome ?? ('Loja ' . $storePdvId),
                    'nome_hiper' => $storeNome,
                    'alias' => $storeAlias,
                    'ativa' => true,
                    'fonte' => 'HIPER',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($hasPdvLojasGuid) {
                    $insert['guid'] = $storeGuid;
                    $insert['id_hiper'] = $storeHiperId;
                }

                DB::table('pdv_lojas')->insert($insert);
            }
        }

        if ($this->supportsPdvUsuariosTable()) {
            $hasPdvUsuariosLogin = $this->supportsPdvUsuariosLoginColumn();
            $hasPdvUsuariosGuid = $this->supportsPdvUsuariosGuid();
            /** @var array<int, array{nome:?string,papel:string,login:?string,guid:?string,id_hiper:?int,email:?string,documento:?string,tipo:?string}> $observedUsers */
            $observedUsers = [];
            $observeUser = static function (&$observedUsers, ?int $userId, ?string $userName, ?string $userLogin, string $papel, ?string $guid = null, ?int $idHiper = null, ?string $email = null, ?string $documento = null, ?string $tipo = null): void {
                if ($userId === null || $userId <= 0) {
                    return;
                }

                $papel = strtoupper(trim($papel)) ?: 'VENDEDOR';
                if (!isset($observedUsers[$userId])) {
                    $observedUsers[$userId] = [
                        'nome' => $userName,
                        'papel' => $papel,
                        'login' => $userLogin,
                        'guid' => $guid,
                        'id_hiper' => $idHiper,
                        'email' => $email,
                        'documento' => $documento,
                        'tipo' => $tipo,
                    ];

                    return;
                }

                if ($observedUsers[$userId]['nome'] === null && $userName !== null) {
                    $observedUsers[$userId]['nome'] = $userName;
                }
                if ($observedUsers[$userId]['login'] === null && $userLogin !== null) {
                    $observedUsers[$userId]['login'] = $userLogin;
                }
                if (($observedUsers[$userId]['guid'] ?? null) === null && $guid !== null) {
                    $observedUsers[$userId]['guid'] = $guid;
                }
                if (($observedUsers[$userId]['id_hiper'] ?? null) === null && $idHiper !== null) {
                    $observedUsers[$userId]['id_hiper'] = $idHiper;
                }
                if (($observedUsers[$userId]['email'] ?? null) === null && $email !== null) {
                    $observedUsers[$userId]['email'] = $email;
                }
                if (($observedUsers[$userId]['documento'] ?? null) === null && $documento !== null) {
                    $observedUsers[$userId]['documento'] = $documento;
                }
                if (($observedUsers[$userId]['tipo'] ?? null) === null && $tipo !== null) {
                    $observedUsers[$userId]['tipo'] = $tipo;
                }

                if ($papel === 'OPERADOR') {
                    $observedUsers[$userId]['papel'] = 'OPERADOR';
                }
            };

            $turnos = data_get($payload, 'turnos', []);
            if (is_array($turnos)) {
                foreach ($turnos as $turno) {
                    if (!is_array($turno)) {
                        continue;
                    }

                    $observeUser(
                        $observedUsers,
                        $this->asInt(data_get($turno, 'operador.id_usuario')),
                        $this->asString(data_get($turno, 'operador.nome')),
                        $this->asString(data_get($turno, 'operador.login')),
                        'OPERADOR',
                        $this->asString(data_get($turno, 'operador.guid')),
                        $this->asInt(data_get($turno, 'operador.id_hiper')),
                        null,
                        null,
                        null
                    );
                    $observeUser(
                        $observedUsers,
                        $this->asInt(data_get($turno, 'responsavel.id_usuario')),
                        $this->asString(data_get($turno, 'responsavel.nome')),
                        $this->asString(data_get($turno, 'responsavel.login')),
                        'VENDEDOR',
                        $this->asString(data_get($turno, 'responsavel.guid')),
                        $this->asInt(data_get($turno, 'responsavel.id_hiper')),
                        null,
                        null,
                        null
                    );
                }
            }

            $snapshotTurnos = data_get($payload, 'snapshot_turnos', []);
            if (is_array($snapshotTurnos)) {
                foreach ($snapshotTurnos as $turno) {
                    if (!is_array($turno)) {
                        continue;
                    }

                    $observeUser(
                        $observedUsers,
                        $this->asInt(data_get($turno, 'operador.id_usuario')),
                        $this->asString(data_get($turno, 'operador.nome')),
                        $this->asString(data_get($turno, 'operador.login')),
                        'OPERADOR',
                        $this->asString(data_get($turno, 'operador.guid')),
                        $this->asInt(data_get($turno, 'operador.id_hiper')),
                        null,
                        null,
                        null
                    );
                    $observeUser(
                        $observedUsers,
                        $this->asInt(data_get($turno, 'responsavel.id_usuario')),
                        $this->asString(data_get($turno, 'responsavel.nome')),
                        $this->asString(data_get($turno, 'responsavel.login')),
                        'VENDEDOR',
                        $this->asString(data_get($turno, 'responsavel.guid')),
                        $this->asInt(data_get($turno, 'responsavel.id_hiper')),
                        null,
                        null,
                        null
                    );
                }
            }

            $vendas = data_get($payload, 'vendas', []);
            if (is_array($vendas)) {
                foreach ($vendas as $venda) {
                    if (!is_array($venda)) {
                        continue;
                    }

                    $itens = data_get($venda, 'itens', []);
                    if (!is_array($itens)) {
                        continue;
                    }

                    foreach ($itens as $item) {
                        if (!is_array($item)) {
                            continue;
                        }

                        $observeUser(
                            $observedUsers,
                            $this->asInt(data_get($item, 'vendedor.id_usuario')),
                            $this->asString(data_get($item, 'vendedor.nome')),
                            $this->asString(data_get($item, 'vendedor.login')),
                            'VENDEDOR',
                            $this->asString(data_get($item, 'vendedor.guid')),
                            $this->asInt(data_get($item, 'vendedor.id_hiper')),
                            null,
                            null,
                            null
                        );
                    }
                }
            }

            $snapshotVendas = data_get($payload, 'snapshot_vendas', []);
            if (is_array($snapshotVendas)) {
                foreach ($snapshotVendas as $venda) {
                    if (!is_array($venda)) {
                        continue;
                    }

                    $observeUser(
                        $observedUsers,
                        $this->asInt(data_get($venda, 'vendedor.id_usuario')),
                        $this->asString(data_get($venda, 'vendedor.nome')),
                        $this->asString(data_get($venda, 'vendedor.login')),
                        'VENDEDOR',
                        $this->asString(data_get($venda, 'vendedor.guid')),
                        $this->asInt(data_get($venda, 'vendedor.id_hiper')),
                        null,
                        null,
                        null
                    );
                }
            }

            $resumoByVendor = data_get($payload, 'resumo.by_vendor', []);
            if (is_array($resumoByVendor)) {
                foreach ($resumoByVendor as $summary) {
                    if (!is_array($summary)) {
                        continue;
                    }

                    $observeUser(
                        $observedUsers,
                        $this->asInt(data_get($summary, 'id_usuario')),
                        $this->asString(data_get($summary, 'nome')),
                        $this->asString(data_get($summary, 'login')),
                        'VENDEDOR',
                        $this->asString(data_get($summary, 'guid')),
                        $this->asInt(data_get($summary, 'id_hiper')),
                        null,
                        null,
                        null
                    );
                }
            }

            // Backfill de login para usuarios observados com base no mapping global, quando payload nao trouxer login.
            if ($observedUsers !== [] && $this->supportsUserMappingsTable() && Schema::hasColumn('pdv_user_mappings', 'pdv_user_login')) {
                $mappedLogins = DB::table('pdv_user_mappings')
                    ->whereIn('pdv_user_id', array_keys($observedUsers))
                    ->where('active', true)
                    ->pluck('pdv_user_login', 'pdv_user_id');

                foreach ($mappedLogins as $pdvUserId => $pdvLogin) {
                    $userId = (int) $pdvUserId;
                    if ($userId <= 0 || !isset($observedUsers[$userId])) {
                        continue;
                    }

                    if ($observedUsers[$userId]['login'] !== null) {
                        continue;
                    }

                    $normalizedLogin = $this->asString($pdvLogin);
                    if ($normalizedLogin !== null) {
                        $observedUsers[$userId]['login'] = $normalizedLogin;
                    }
                }
            }

            foreach ($observedUsers as $userId => $userData) {
                $existingUserColumns = ['id', 'papel'];
                if ($hasPdvUsuariosLogin) {
                    $existingUserColumns[] = 'login_hiper';
                }

                $existingUser = DB::table('pdv_usuarios')
                    ->where('id_usuario_hiper', $userId)
                    ->first($existingUserColumns);

                if ($existingUser) {
                    $updates = [
                        'updated_at' => $now,
                    ];

                    if ($userData['nome'] !== null) {
                        $updates['nome_hiper'] = $userData['nome'];
                    }

                    $existingPapel = strtoupper((string) ($existingUser->papel ?? ''));
                    if ($existingPapel !== 'OPERADOR' && $userData['papel'] === 'OPERADOR') {
                        $updates['papel'] = 'OPERADOR';
                    }
                    if ($hasPdvUsuariosLogin && $userData['login'] !== null) {
                        $updates['login_hiper'] = $userData['login'];
                    }
                    if ($hasPdvUsuariosGuid) {
                        if ($userData['guid'] !== null)
                            $updates['guid'] = $userData['guid'];
                        if ($userData['email'] !== null)
                            $updates['email'] = $userData['email'];
                        if ($userData['documento'] !== null)
                            $updates['documento'] = $userData['documento'];
                        if ($userData['tipo'] !== null)
                            $updates['tipo'] = $userData['tipo'];
                    }

                    DB::table('pdv_usuarios')
                        ->where('id_usuario_hiper', $userId)
                        ->update($updates);
                } else {
                    $insert = [
                        'id_usuario_hiper' => $userId,
                        'nome_padronizado' => $userData['nome'] ?? ('Usuario ' . $userId),
                        'nome_hiper' => $userData['nome'],
                        'papel' => $userData['papel'] === 'OPERADOR' ? 'OPERADOR' : 'VENDEDOR',
                        'ativo' => true,
                        'fonte' => 'HIPER',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    if ($hasPdvUsuariosLogin) {
                        $insert['login_hiper'] = $userData['login'];
                    }
                    if ($hasPdvUsuariosGuid) {
                        $insert['guid'] = $userData['guid'];
                        $insert['email'] = $userData['email'];
                        $insert['documento'] = $userData['documento'];
                        $insert['tipo'] = $userData['tipo'];
                    }

                    DB::table('pdv_usuarios')->insert($insert);
                }
            }
        }

        if ($this->supportsPdvMeiosPagamentoTable()) {
            /** @var array<int, ?string> $observedPaymentMethods */
            $observedPaymentMethods = [];
            $observePaymentMethod = static function (&$observedPaymentMethods, ?int $idFinalizador, ?string $name): void {
                if ($idFinalizador === null || $idFinalizador <= 0) {
                    return;
                }

                if (!array_key_exists($idFinalizador, $observedPaymentMethods)) {
                    $observedPaymentMethods[$idFinalizador] = $name;
                    return;
                }

                if ($observedPaymentMethods[$idFinalizador] === null && $name !== null) {
                    $observedPaymentMethods[$idFinalizador] = $name;
                }
            };

            $collectTurnoPaymentMethods = function (mixed $turnos) use (&$observedPaymentMethods, $observePaymentMethod): void {
                if (!is_array($turnos)) {
                    return;
                }

                foreach ($turnos as $turno) {
                    if (!is_array($turno)) {
                        continue;
                    }

                    $sections = [
                        data_get($turno, 'totais_sistema.por_pagamento', []),
                        data_get($turno, 'fechamento_declarado.por_pagamento', []),
                        data_get($turno, 'falta_caixa.por_pagamento', []),
                    ];

                    foreach ($sections as $section) {
                        if (!is_array($section)) {
                            continue;
                        }

                        foreach ($section as $payment) {
                            if (!is_array($payment)) {
                                continue;
                            }

                            $observePaymentMethod(
                                $observedPaymentMethods,
                                $this->asInt(data_get($payment, 'id_finalizador')),
                                $this->asString(data_get($payment, 'meio'))
                            );
                        }
                    }
                }
            };

            $collectTurnoPaymentMethods(data_get($payload, 'turnos', []));
            $collectTurnoPaymentMethods(data_get($payload, 'snapshot_turnos', []));

            $vendas = data_get($payload, 'vendas', []);
            if (is_array($vendas)) {
                foreach ($vendas as $venda) {
                    if (!is_array($venda)) {
                        continue;
                    }

                    $payments = data_get($venda, 'pagamentos', []);
                    if (!is_array($payments)) {
                        continue;
                    }

                    foreach ($payments as $payment) {
                        if (!is_array($payment)) {
                            continue;
                        }

                        $observePaymentMethod(
                            $observedPaymentMethods,
                            $this->asInt(data_get($payment, 'id_finalizador')),
                            $this->asString(data_get($payment, 'meio'))
                        );
                    }
                }
            }

            foreach ($observedPaymentMethods as $idFinalizador => $paymentName) {
                $existingPaymentMethod = DB::table('pdv_meios_pagamento')
                    ->where('id_finalizador', $idFinalizador)
                    ->first(['id', 'categoria']);

                $categoria = $this->normalizePaymentCategory($paymentName);

                if ($existingPaymentMethod) {
                    $updates = [
                        'updated_at' => $now,
                    ];

                    if ($paymentName !== null) {
                        $updates['nome_hiper'] = $paymentName;
                    }

                    if (
                        ($existingPaymentMethod->categoria === null || trim((string) $existingPaymentMethod->categoria) === '')
                        && $categoria !== null
                    ) {
                        $updates['categoria'] = $categoria;
                    }

                    DB::table('pdv_meios_pagamento')
                        ->where('id_finalizador', $idFinalizador)
                        ->update($updates);
                } else {
                    DB::table('pdv_meios_pagamento')->insert([
                        'id_finalizador' => $idFinalizador,
                        'nome_padronizado' => $paymentName ?? ('Finalizador ' . $idFinalizador),
                        'nome_hiper' => $paymentName,
                        'categoria' => $categoria,
                        'ativo' => true,
                        'fonte' => 'HIPER',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    private function normalizePaymentCategory(?string $paymentName): ?string
    {
        if ($paymentName === null) {
            return null;
        }

        $normalized = Str::upper(Str::ascii($paymentName));
        if (str_contains($normalized, 'PIX')) {
            return 'PIX';
        }
        if (str_contains($normalized, 'DEBIT')) {
            return 'DEBITO';
        }
        if (str_contains($normalized, 'CREDIT')) {
            return 'CREDITO';
        }
        if (str_contains($normalized, 'DINHEIRO') || str_contains($normalized, 'CASH')) {
            return 'DINHEIRO';
        }
        if (str_contains($normalized, 'VALE')) {
            return 'VALE';
        }
        if (str_contains($normalized, 'CHEQUE')) {
            return 'CHEQUE';
        }

        return null;
    }

    private function resolveVendaCanal(PdvSync $sync, int $storePdvId, int $idOperacao, mixed $value): string
    {
        $rawValue = $this->asString($value);
        if ($rawValue === null) {
            return self::CANAL_HIPER_CAIXA;
        }

        $normalized = Str::upper(str_replace('-', '_', $rawValue));
        if (in_array($normalized, [self::CANAL_HIPER_CAIXA, self::CANAL_HIPER_LOJA], true)) {
            return $normalized;
        }

        $this->markRuntimeRiskFlag('venda_canal_invalid');

        Log::warning('pdv.sync.venda_canal_invalid', [
            'pdv_sync_id' => $sync->id,
            'sync_id' => $sync->sync_id,
            'store_pdv_id' => $storePdvId,
            'id_operacao' => $idOperacao,
            'canal' => $rawValue,
        ]);

        return self::CANAL_HIPER_CAIXA;
    }

    private function resolveTurnoCanal(PdvSync $sync, int $storePdvId, mixed $value): string
    {
        $rawValue = $this->asString($value);
        if ($rawValue === null) {
            return self::CANAL_HIPER_CAIXA;
        }

        $normalized = Str::upper(str_replace('-', '_', $rawValue));
        if (in_array($normalized, [self::CANAL_HIPER_CAIXA, self::CANAL_HIPER_LOJA], true)) {
            return $normalized;
        }

        $this->markRuntimeRiskFlag('turno_canal_invalid');

        Log::warning('pdv.sync.turno_canal_invalid', [
            'pdv_sync_id' => $sync->id,
            'sync_id' => $sync->sync_id,
            'store_pdv_id' => $storePdvId,
            'canal' => $rawValue,
        ]);

        return self::CANAL_HIPER_CAIXA;
    }

    /**
     * @return array{
     *   by_id:array<int, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>,
     *   by_login:array<string, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>
     * }
     */
    private function resolveUserMappings(): array
    {
        if (!$this->supportsUserMappingsTable()) {
            return [
                'by_id' => [],
                'by_login' => [],
            ];
        }

        return app(PdvUserResolver::class)->loadActiveMappings();
    }

    /**
     * @param array{
     *   by_id:array<int, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>,
     *   by_login:array<string, array{user_id:int|null,is_store_operator:bool,pdv_user_name:?string,pdv_user_login:?string}>
     * } $userMappings
     */
    private function resolveMappedUserId(int $storePdvId, ?int $pdvUserId, ?string $pdvUserLogin, array $userMappings, ?string $pdvUserGuid = null): ?int
    {
        $resolution = app(PdvUserResolver::class)->resolve($storePdvId, $pdvUserId, $pdvUserLogin, $userMappings, $pdvUserGuid);

        // Fallback: Resolve via Deep Lookup (pdv_usuarios table) if standard resolution failed but GUID is present
        if (($resolution['status'] === 'missing' || $resolution['status'] === 'empty') && $pdvUserGuid !== null) {
            $pdvUser = DB::table('pdv_usuarios')->where('guid_usuario', $pdvUserGuid)->first(['id_usuario_hiper']);

            if ($pdvUser && $storePdvId > 0) {
                // Check for existing mapping using the resolved ID
                $mapping = DB::table('pdv_user_mappings')
                    ->where('store_pdv_id', $storePdvId)
                    ->where('pdv_user_id', $pdvUser->id_usuario_hiper)
                    ->where('active', true)
                    ->first(['id', 'user_id', 'guid_usuario']);

                if ($mapping) {
                    // Self-healing: Update mapping with GUID if missing
                    if (empty($mapping->guid_usuario)) {
                        DB::table('pdv_user_mappings')
                            ->where('id', $mapping->id)
                            ->update(['guid_usuario' => $pdvUserGuid, 'updated_at' => now()]);
                    }

                    return (int) $mapping->user_id;
                }
            }
        }
        $resolutionFlags = is_array($resolution['flags'] ?? null)
            ? array_values(array_unique($resolution['flags']))
            : [];

        // Self-Healing: If we found the user but the mapping is missing the GUID, update it.
        if (in_array('guid_missing_in_mapping', $resolutionFlags, true)) {
            // We resolved to a user_id, but the mapping didn't have the GUID.
            // Find the mapping (by ID or Login) and update it.
            // Since we don't have the mapping ID explicitly returned, we infer it via pdvUserId/Login match

            // NOTE: We only update if we are SURE. PdvUserResolver returns 'resolved' which means we trusted the mapping.
            if ($resolution['status'] === 'resolved' && $pdvUserGuid !== null && $storePdvId > 0) {
                // Determine criteria to find the specific mapping row
                $query = DB::table('pdv_user_mappings')
                    ->where('store_pdv_id', $storePdvId)
                    ->where('active', true);

                if ($pdvUserId > 0) {
                    $query->where('pdv_user_id', $pdvUserId);
                } elseif ($pdvUserLogin !== null) {
                    $query->where('pdv_user_login', $pdvUserLogin);
                } else {
                    $query = null;
                }

                if ($query) {
                    $query->update(['guid_usuario' => $pdvUserGuid, 'updated_at' => now()]);
                    // Remove the flag so we don't log it as a risk, or keep it to track healing events? 
                    // Let's keep it but maybe log it differently if we wanted. 
                    // For now, standard risk flag is fine, users seeing it will see "Oh it healed".
                }
            }
        }

        foreach ($resolutionFlags as $flag) {
            $this->markRuntimeRiskFlag($flag);
        }

        if ($resolution['status'] === 'resolved' && $resolution['user_id'] !== null) {
            return (int) $resolution['user_id'];
        }

        if ($resolution['status'] === 'operator') {
            return null;
        }

        // Missing vendedor/operador info is not a mapping problem (eg: item sold without vendedor).
        if ($resolution['status'] === 'empty') {
            return null;
        }

        $this->markRuntimeRiskFlag('user_mapping_missing');

        Log::warning('pdv.sync.user_mapping_missing', [
            'pdv_sync_id' => $this->pdvSyncId,
            'store_pdv_id' => $storePdvId,
            'pdv_user_id' => $pdvUserId,
            'pdv_user_login' => $pdvUserLogin,
            'resolution_flags' => $resolutionFlags,
        ]);

        return null;
    }

    private function markRuntimeRiskFlag(string $flag): void
    {
        $flag = trim($flag);
        if ($flag === '') {
            return;
        }

        $this->runtimeRiskFlags[$flag] = $flag;
    }

    private function mergeRuntimeRiskFlags(PdvSync $sync): void
    {
        if ($this->runtimeRiskFlags === []) {
            return;
        }

        $this->appendRiskFlags($sync, array_values($this->runtimeRiskFlags));
        $this->runtimeRiskFlags = [];
    }

    private function supportsTurnoMappedUserId(): bool
    {
        if ($this->hasTurnoMappedUserColumn !== null) {
            return $this->hasTurnoMappedUserColumn;
        }

        return $this->hasTurnoMappedUserColumn = Schema::hasColumn('pdv_turnos', 'operador_user_id');
    }

    private function supportsTurnoOperadorLoginColumn(): bool
    {
        if ($this->hasTurnoOperadorLoginColumn !== null) {
            return $this->hasTurnoOperadorLoginColumn;
        }

        return $this->hasTurnoOperadorLoginColumn = Schema::hasColumn('pdv_turnos', 'operador_login');
    }

    private function supportsTurnoResponsavelLoginColumn(): bool
    {
        if ($this->hasTurnoResponsavelLoginColumn !== null) {
            return $this->hasTurnoResponsavelLoginColumn;
        }

        return $this->hasTurnoResponsavelLoginColumn = Schema::hasColumn('pdv_turnos', 'responsavel_login');
    }

    private function supportsItemMappedUserId(): bool
    {
        if ($this->hasItemMappedUserColumn !== null) {
            return $this->hasItemMappedUserColumn;
        }

        return $this->hasItemMappedUserColumn = Schema::hasColumn('pdv_venda_itens', 'vendedor_user_id');
    }

    private function supportsItemVendedorLoginColumn(): bool
    {
        if ($this->hasItemVendedorLoginColumn !== null) {
            return $this->hasItemVendedorLoginColumn;
        }

        return $this->hasItemVendedorLoginColumn = Schema::hasColumn('pdv_venda_itens', 'vendedor_login');
    }

    private function supportsResumoVendedorLoginColumn(): bool
    {
        if ($this->hasResumoVendedorLoginColumn !== null) {
            return $this->hasResumoVendedorLoginColumn;
        }

        return $this->hasResumoVendedorLoginColumn = Schema::hasColumn('pdv_vendas_resumo', 'vendedor_login');
    }

    private function supportsUserMappingsTable(): bool
    {
        if ($this->hasUserMappingsTable !== null) {
            return $this->hasUserMappingsTable;
        }

        return $this->hasUserMappingsTable = Schema::hasTable('pdv_user_mappings');
    }

    private function supportsPdvLojasTable(): bool
    {
        if ($this->hasPdvLojasTable !== null) {
            return $this->hasPdvLojasTable;
        }

        return $this->hasPdvLojasTable = Schema::hasTable('pdv_lojas');
    }

    private function supportsPdvUsuariosTable(): bool
    {
        if ($this->hasPdvUsuariosTable !== null) {
            return $this->hasPdvUsuariosTable;
        }

        return $this->hasPdvUsuariosTable = Schema::hasTable('pdv_usuarios');
    }

    private function supportsPdvUsuariosLoginColumn(): bool
    {
        if ($this->hasPdvUsuariosLoginColumn !== null) {
            return $this->hasPdvUsuariosLoginColumn;
        }

        return $this->hasPdvUsuariosLoginColumn = Schema::hasTable('pdv_usuarios')
            && Schema::hasColumn('pdv_usuarios', 'login_hiper');
    }

    private function supportsPdvMeiosPagamentoTable(): bool
    {
        if ($this->hasPdvMeiosPagamentoTable !== null) {
            return $this->hasPdvMeiosPagamentoTable;
        }

        return $this->hasPdvMeiosPagamentoTable = Schema::hasTable('pdv_meios_pagamento');
    }

    /**
     * @param array<int, array<string, mixed>> $snapshotRows
     */
    private function touchLastSeenInSnapshot(array $snapshotRows, \DateTimeInterface $seenAt): void
    {
        /** @var array<string, array{store_pdv_id:int,canal:string,id_operacao:int}> $keys */
        $keys = [];
        foreach ($snapshotRows as $row) {
            $storePdvId = (int) ($row['store_pdv_id'] ?? 0);
            $idOperacao = (int) ($row['id_operacao'] ?? 0);
            $canal = strtoupper(trim((string) ($row['canal'] ?? 'HIPER_CAIXA')));
            if ($storePdvId <= 0 || $idOperacao <= 0 || $canal === '') {
                continue;
            }

            $composite = $storePdvId . '|' . $canal . '|' . $idOperacao;
            $keys[$composite] = [
                'store_pdv_id' => $storePdvId,
                'canal' => $canal,
                'id_operacao' => $idOperacao,
            ];
        }

        foreach ($keys as $key) {
            DB::table('pdv_vendas')
                ->where('store_pdv_id', $key['store_pdv_id'])
                ->where('canal', $key['canal'])
                ->where('id_operacao', $key['id_operacao'])
                ->update(['last_seen_in_snapshot_at' => $seenAt]);
        }
    }

    private function supportsPdvVendasLastSeenColumn(): bool
    {
        if ($this->hasPdvVendasLastSeenColumn !== null) {
            return $this->hasPdvVendasLastSeenColumn;
        }

        if (!Schema::hasTable('pdv_vendas')) {
            return $this->hasPdvVendasLastSeenColumn = false;
        }

        return $this->hasPdvVendasLastSeenColumn = Schema::hasColumn('pdv_vendas', 'last_seen_in_snapshot_at');
    }

    private function supportsClosureUuid(): bool
    {
        if ($this->hasTurnoClosureUuidColumn !== null) {
            return $this->hasTurnoClosureUuidColumn;
        }

        return $this->hasTurnoClosureUuidColumn = Schema::hasColumn('pdv_turnos', 'closure_uuid');
    }

    private function supportsPagamentoUuid(): bool
    {
        if ($this->hasPagamentoUuidColumn !== null) {
            return $this->hasPagamentoUuidColumn;
        }

        return $this->hasPagamentoUuidColumn = Schema::hasColumn('pdv_turno_pagamentos', 'pagamento_uuid');
    }

    private function supportsOperadorGuid(): bool
    {
        if ($this->hasTurnoOperadorGuidColumn !== null) {
            return $this->hasTurnoOperadorGuidColumn;
        }

        return $this->hasTurnoOperadorGuidColumn = Schema::hasColumn('pdv_turnos', 'operador_guid');
    }

    private function supportsVendedorGuid(): bool
    {
        if ($this->hasItemVendedorGuidColumn !== null) {
            return $this->hasItemVendedorGuidColumn;
        }

        return $this->hasItemVendedorGuidColumn = Schema::hasColumn('pdv_venda_itens', 'vendedor_guid');
    }

    private function supportsPdvLojasGuid(): bool
    {
        if ($this->hasPdvLojasGuidColumn !== null) {
            return $this->hasPdvLojasGuidColumn;
        }

        return $this->hasPdvLojasGuidColumn = Schema::hasColumn('pdv_lojas', 'guid');
    }

    private function supportsPdvUsuariosGuid(): bool
    {
        if ($this->hasPdvUsuariosGuidColumn !== null) {
            return $this->hasPdvUsuariosGuidColumn;
        }

        return $this->hasPdvUsuariosGuidColumn = Schema::hasColumn('pdv_usuarios', 'guid');
    }


    /**
     * @return array<int, string>
     */
    private function buildPagamentoUpdateColumns(bool $hasPagamentoUuid): array
    {
        $columns = [
            'store_id',
            'meio_pagamento',
            'total',
            'qtd_vendas',
            'last_sync_id',
            'updated_at',
        ];

        if ($hasPagamentoUuid) {
            $columns[] = 'pagamento_uuid';
            $columns[] = 'operacao_uuid';
        }

        return $columns;
    }

    /**
     * @param array<int, string> $newFlags
     */
    private function appendRiskFlags(PdvSync $sync, array $newFlags): void
    {
        $existing = $sync->risk_flags;
        $existing = is_array($existing) ? $existing : [];

        $sync->risk_flags = array_values(array_unique(array_merge($existing, $newFlags)));
        $sync->save();
    }

    private function storeLockKey(PdvSync $sync): string
    {
        if ($sync->store_id !== null) {
            return 'pdv:store:' . $sync->store_id;
        }

        $alias = trim((string) ($sync->store_alias ?? ''));
        if ($alias !== '') {
            return 'pdv:store:pdv:' . $sync->store_pdv_id . ':' . sha1(strtolower($alias));
        }

        return 'pdv:store:pdv:' . $sync->store_pdv_id . ':no-alias';
    }

    private function asNormalizedLowerText(mixed $value): ?string
    {
        $normalized = $this->asString($value);
        if ($normalized === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($normalized));

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeCnpj(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value);
        if (!is_string($digits) || $digits === '') {
            return null;
        }

        return $digits;
    }
}
