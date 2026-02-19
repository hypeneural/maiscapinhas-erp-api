<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pdv\PdvSyncIngestRequest;
use App\Http\Traits\ApiResponse;
use App\Jobs\ProcessPdvSyncJob;
use App\Models\PdvSync;
use App\Models\PdvSyncPayload;
use App\Support\Audit\AuditContext;
use App\Support\Pdv\PdvDateTime;
use App\Support\Pdv\PdvJsonSchemaValidator;
use App\Support\Pdv\PdvStoreResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * @group PDV - Sync
 *
 * Endpoint de ingestao do webhook PDV Sync Agent v3.
 *
 * Contrato:
 * - `schema_version` suportado: `3.0`, `3.1` e `4.0`
 * - idempotencia por `integrity.sync_id`
 * - payload validado por regras Laravel + JSON Schema
 * - enfileiramento assincorno para processamento (`ProcessPdvSyncJob`)
 *
 * Seguranca:
 * - Middleware custom `pdv.signature`
 * - Pode operar em modo HMAC, Bearer, Auto ou None (controlado por `config('pdv.*')`)
 */
class PdvSyncController extends Controller
{
    use ApiResponse;

    /**
     * Ingerir payload do PDV Sync
     *
     * Recebe o payload bruto do agente PDV e cria o registro de sync.
     * O processamento pesado e executado em fila.
     *
     * @unauthenticated
     *
     * @header X-PDV-Schema-Version string Versao do schema enviada no header. Esperado: `3.0` ou `3.1`. Example: 3.1
     * @header X-PDV-Timestamp integer Timestamp Unix para assinatura HMAC (obrigatorio em modo HMAC). Example: 1765212000
     * @header X-PDV-Signature string Assinatura HMAC SHA-256 no formato hex (ou `sha256=...`). Example: 9b7a3f...
     * @header Authorization string Token bearer legado/alternativo (`Bearer {token}`), quando habilitado por config. Example: Bearer abc123
     * @header X-Request-Id string ID de correlacao opcional. Example: req-pdv-001
     *
     * @bodyParam schema_version string required Versao do schema. Valores aceitos: `3.0`, `3.1`, `4.0`, `5.0`. Example: 5.0
     * @bodyParam event_type string Tipo do evento (`sales`, `turno_closure`, `mixed`). Example: mixed
     * @bodyParam store object required Dados da loja origem.
     * @bodyParam store.id_ponto_venda integer required ID da loja no PDV. Example: 13
     * @bodyParam store.nome string Nome da loja no PDV. Example: Mais Capinhas Porto Belo
     * @bodyParam store.alias string Alias da loja no agente. Example: porto-belo-13
     * @bodyParam store.cnpj string CNPJ da loja (quando disponivel no agente). Example: 61063019000333
     * @bodyParam window object required Janela de sincronizacao.
     * @bodyParam window.from string required Inicio da janela (ISO-8601). Example: 2026-02-12T09:50:00-03:00
     * @bodyParam window.to string required Fim da janela (ISO-8601). Example: 2026-02-12T10:00:00-03:00
     * @bodyParam window.minutes integer Duracao da janela em minutos. Example: 10
     * @bodyParam vendas array Lista de vendas da janela.
     * @bodyParam turnos array Lista de turnos (Legacy/V4) ou `turnos_abertos`/`turnos_fechados` (V5).
     * @bodyParam turnos[].operador.login string Login do operador (contrato atual, enviado em payloads 3.0/3.1). Example: operador.v3
     * @bodyParam turnos[].responsavel.login string Login do responsavel/vendedor principal (contrato atual, enviado em payloads 3.0/3.1). Example: vendedor.lider
     * @bodyParam turnos_abertos array (V5) Lista de turnos em aberto.
     * @bodyParam turnos_fechados array (V5) Lista de turnos fechados recentementes.
     * @bodyParam snapshot_turnos array Snapshot dos ultimos turnos fechados (Legacy, use turnos_fechados em V5).
     * @bodyParam snapshot_turnos[].operador.login string Login do operador no snapshot (contrato atual, enviado em payloads 3.0/3.1). Example: operador.v3
     * @bodyParam snapshot_turnos[].responsavel.login string Login do responsavel no snapshot (contrato atual, enviado em payloads 3.0/3.1). Example: vendedor.lider
     * @bodyParam snapshot_vendas array Snapshot das ultimas vendas.
     * @bodyParam snapshot_vendas[].vendedor.login string Login do vendedor no snapshot (contrato atual, enviado em payloads 3.0/3.1). Example: vendedor.lider
     * @bodyParam ops object Metadados operacionais da janela.
     * @bodyParam ops.count integer Quantidade de operacoes de caixa (`HIPER_CAIXA`). Example: 5
     * @bodyParam ops.ids integer[] IDs de operacao do canal caixa. Example: [12345,12346]
     * @bodyParam ops.loja_count integer Quantidade de operacoes do canal loja (`HIPER_LOJA`). Example: 2
     * @bodyParam ops.loja_ids integer[] IDs de operacao do canal loja. Example: [99001,99002]
     * @bodyParam integrity object required Bloco de integridade.
     * @bodyParam integrity.sync_id string required Identificador deterministico do sync. Example: a1b2c3d4e5f6
     * @bodyParam integrity.warnings string[] Warnings operacionais emitidos pelo agente.
     * @bodyParam vendas[].itens[].vendedor.login string Login do vendedor por item (contrato atual, enviado em payloads 3.0/3.1). Example: maria.silva
     * @bodyParam resumo.by_vendor[].login string Login do vendedor no resumo agregado (contrato atual, enviado em payloads 3.0/3.1). Example: maria.silva
     *
     * @response 201 {
     *   "data": {
     *     "status": "created",
     *     "processing_status": "queued",
     *     "duplicate": false,
     *     "sync_id": "a1b2c3d4e5f6",
     *     "pdv_sync_id": 31,
     *     "schema_version": "3.0",
     *     "event_type": "mixed",
     *     "request_id": "req-pdv-001",
     *     "timestamp_mode": "tolerant",
     *     "timestamp_out_of_window": false,
     *     "risk_flags": [],
     *     "auth_mode": "hmac",
     *     "message": "Sync received and queued for processing."
     *   },
     *   "meta": {
     *     "request_id": "req-pdv-001",
     *     "timestamp": "2026-02-12T10:00:00+00:00"
     *   }
     * }
     * @response 200 {"data":{"status":"duplicate","duplicate":true,"sync_id":"a1b2c3d4e5f6","message":"Sync already received."}}
     * @response 401 {"message":"Missing or invalid webhook authentication headers."}
     * @response 403 {"message":"Invalid webhook signature."}
     * @response 422 {"error":"validation","message":"Payload does not match JSON schema.","details":{"schema":["..."]}}
     * @response 503 {"message":"Webhook service unavailable."}
     */
    public function ingest(PdvSyncIngestRequest $request): JsonResponse
    {
        $startedAt = microtime(true);
        $payload = $request->validated();
        $rawPayloadArray = $request->json()->all();
        $syncId = (string) data_get($payload, 'integrity.sync_id');
        $storePdvId = (int) data_get($payload, 'store.id_ponto_venda');
        $schemaVersion = (string) data_get($payload, 'schema_version');
        [$eventType, $unknownEventType] = $this->normalizeEventType(data_get($payload, 'event_type'));
        $authMode = (string) $request->attributes->get('pdv_auth_mode', 'unknown');
        $requestId = app(AuditContext::class)->getRequestId() ?: (string) $request->header('X-Request-Id', '');
        $rawPayload = $request->getContent();
        $baseLogContext = [
            'sync_id' => $syncId,
            'store_pdv_id' => $storePdvId,
            'schema_version' => $schemaVersion,
            'event_type' => $eventType,
            'request_id' => $requestId,
            'auth_mode' => $authMode,
            'remote_ip' => $request->ip(),
            'forwarded_for' => (string) $request->header('X-Forwarded-For', ''),
            'schema_header' => (string) $request->header('X-PDV-Schema-Version', ''),
            'payload_bytes' => strlen($rawPayload),
        ];

        $this->pdvLog('info', 'pdv.sync.received', $baseLogContext);

        // Idempotency first: duplicates are always accepted (200).
        $existingSync = PdvSync::query()->where('sync_id', $syncId)->first();
        if ($existingSync) {
            $this->pdvLog('info', 'pdv.sync.ingest', [
                'sync_id' => $syncId,
                'store_pdv_id' => $storePdvId,
                'schema_version' => $existingSync->schema_version,
                'event_type' => $existingSync->event_type ?? PdvSync::EVENT_TYPE_SALES,
                'request_id' => $requestId,
                'status' => 'duplicate',
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'pdv_sync_id' => $existingSync->id,
                'auth_mode' => $authMode,
            ]);

            return $this->success([
                'status' => 'duplicate',
                'duplicate' => true,
                'sync_id' => $syncId,
                'pdv_sync_id' => $existingSync->id,
                'schema_version' => $existingSync->schema_version,
                'event_type' => $existingSync->event_type ?? PdvSync::EVENT_TYPE_SALES,
                'request_id' => $requestId,
                'auth_mode' => $authMode,
                'message' => 'Sync already received.',
            ]);
        }

        $headerSchemaVersion = trim((string) $request->header('X-PDV-Schema-Version', ''));
        $supportedSchemaVersions = config('pdv.supported_schema_versions', ['3.0', '3.1']);
        $supportedSchemaVersions = is_array($supportedSchemaVersions) ? $supportedSchemaVersions : ['3.0', '3.1'];

        if ($headerSchemaVersion !== '' && !in_array($headerSchemaVersion, $supportedSchemaVersions, true)) {
            return $this->pdvValidationError(
                'Unsupported schema version informed in header.',
                [
                    'X-PDV-Schema-Version' => [
                        'Unsupported schema version in header.',
                    ],
                ],
                array_merge($baseLogContext, ['header_schema_version' => $headerSchemaVersion])
            );
        }

        if ($headerSchemaVersion !== '' && $headerSchemaVersion !== $schemaVersion && !str_starts_with($schemaVersion, $headerSchemaVersion)) {
            return $this->pdvValidationError(
                'Schema version header does not match payload.',
                [
                    'schema_version' => [
                        'X-PDV-Schema-Version must match payload schema_version.',
                    ],
                ],
                array_merge($baseLogContext, ['header_schema_version' => $headerSchemaVersion])
            );
        }

        $schemaValidationPayload = is_array($rawPayloadArray) ? $rawPayloadArray : $payload;
        $schemaValidation = app(PdvJsonSchemaValidator::class)->validate($schemaValidationPayload);
        if ($schemaValidation['status'] === 'invalid') {
            return $this->pdvValidationError(
                'Payload does not match JSON schema.',
                [
                    'schema' => $schemaValidation['errors'],
                ],
                $baseLogContext
            );
        }

        if ($schemaValidation['status'] === 'error') {
            $this->pdvLog('error', 'pdv.sync.schema_validation', [
                'sync_id' => $syncId,
                'store_pdv_id' => $storePdvId,
                'schema_version' => $schemaVersion,
                'message' => $schemaValidation['message'],
                'schema_path' => $schemaValidation['schema_path'],
            ]);

            return response()->json([
                'message' => 'Webhook schema validator unavailable.',
            ], 503);
        }

        $payloadSha256 = hash('sha256', $rawPayload);
        $payloadBytes = strlen($rawPayload);
        $headerTimestampRaw = (string) $request->header('X-PDV-Timestamp', '');
        $headerTimestamp = ctype_digit($headerTimestampRaw) ? (int) $headerTimestampRaw : null;
        $toleranceSeconds = (int) config('pdv.timestamp_tolerance_seconds', 600);
        $timestampMode = (string) config('pdv.timestamp_mode', 'tolerant');
        $timestampSkewSeconds = $headerTimestamp !== null
            ? abs(now()->timestamp - $headerTimestamp)
            : null;
        $timestampOutOfWindow = $timestampSkewSeconds !== null
            ? $timestampSkewSeconds > $toleranceSeconds
            : false;

        if ($timestampOutOfWindow && $timestampMode === 'strict') {
            $this->pdvLog('warning', 'pdv.sync.ingest', [
                'sync_id' => $syncId,
                'store_pdv_id' => $storePdvId,
                'schema_version' => $schemaVersion,
                'request_id' => $requestId,
                'status' => 'rejected_strict_timestamp',
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'timestamp_skew_seconds' => $timestampSkewSeconds,
                'auth_mode' => $authMode,
            ]);

            return $this->pdvValidationError(
                'Timestamp is outside the accepted window for new syncs.',
                [
                    'timestamp' => [
                        "Payload timestamp differs {$timestampSkewSeconds}s from server time.",
                    ],
                ],
                array_merge($baseLogContext, [
                    'timestamp_skew_seconds' => $timestampSkewSeconds,
                    'timestamp_mode' => $timestampMode,
                ])
            );
        }

        $storeAlias = $this->normalizeAlias(data_get($payload, 'store.alias'));
        $storeName = $this->normalizeAlias(data_get($payload, 'store.nome'));
        $storeCnpj = $this->normalizeCnpj(data_get($payload, 'store.cnpj'));
        $storeGuid = $this->normalizeAlias(data_get($payload, 'store.guid') ?? data_get($payload, 'store.LojaId'));
        $storeResolution = app(PdvStoreResolver::class)->resolve(
            $storePdvId,
            $storeAlias,
            $storeName,
            $storeCnpj,
            $storeGuid
        );
        $storeId = $storeResolution['store_id'];
        $mappedAlias = $this->normalizeAlias($storeResolution['mapped_alias'] ?? null);
        $resolutionRiskFlags = is_array($storeResolution['risk_flags'] ?? null)
            ? $storeResolution['risk_flags']
            : [];
        $aliasMismatch = in_array('store_alias_mismatch', $resolutionRiskFlags, true);
        $blockOnAliasMismatch = (bool) config('pdv.block_on_alias_mismatch', false);
        $shouldBlock = $aliasMismatch && $blockOnAliasMismatch;

        $warnings = data_get($payload, 'integrity.warnings', []);
        $warnings = is_array($warnings) ? array_values($warnings) : [];

        $riskFlags = [];
        if ($timestampOutOfWindow) {
            $riskFlags[] = 'timestamp_out_of_window';
        }
        if ($headerTimestamp === null && $authMode === 'hmac') {
            $riskFlags[] = 'timestamp_missing';
        }
        $riskFlags = array_merge($riskFlags, $resolutionRiskFlags);
        if ($shouldBlock) {
            $riskFlags[] = 'store_alias_mismatch_blocked';
        }
        if ($authMode === 'bearer_fallback') {
            $riskFlags[] = 'auth_bearer_fallback';
        }
        if ($unknownEventType) {
            $riskFlags[] = 'event_type_unknown';
        }
        $riskFlags = array_values(array_unique(array_merge(
            $riskFlags,
            $this->semanticEventTypeRiskFlags($payload, $eventType),
            $this->warningRiskFlags($warnings)
        )));

        $windowFrom = PdvDateTime::parseToUtc((string) data_get($payload, 'window.from'));
        $windowTo = PdvDateTime::parseToUtc((string) data_get($payload, 'window.to'));
        if ($windowFrom === null || $windowTo === null) {
            return $this->pdvValidationError(
                'Invalid window datetime format.',
                [
                    'window' => [
                        'window.from and window.to must be valid datetimes.',
                    ],
                ],
                $baseLogContext
            );
        }

        $agentVersion = data_get($payload, 'agent.version');
        $agentMachine = data_get($payload, 'agent.machine');
        $opsCount = (int) data_get($payload, 'ops.count', 0);
        $opsLojaCount = (int) data_get($payload, 'ops.loja_count', 0);
        $opsLojaIdsRaw = data_get($payload, 'ops.loja_ids', []);
        $opsLojaIds = is_array($opsLojaIdsRaw) ? array_values(array_filter(array_map(
            static fn(mixed $value): int => (int) $value,
            $opsLojaIdsRaw
        ), static fn(int $value): bool => $value > 0)) : [];
        $snapshotTurnosRaw = data_get($payload, 'snapshot_turnos', []);
        $snapshotTurnosCount = is_array($snapshotTurnosRaw) ? count($snapshotTurnosRaw) : 0;
        $snapshotVendasRaw = data_get($payload, 'snapshot_vendas', []);
        $snapshotVendasCount = is_array($snapshotVendasRaw) ? count($snapshotVendasRaw) : 0;

        $inserted = 0;
        $sync = null;

        DB::transaction(function () use (&$inserted, &$sync, $syncId, $storePdvId, $storeId, $storeAlias, $schemaVersion, $eventType, $requestId, $windowFrom, $windowTo, $agentVersion, $agentMachine, $opsCount, $opsLojaCount, $opsLojaIds, $snapshotTurnosCount, $snapshotVendasCount, $warnings, $timestampSkewSeconds, $timestampOutOfWindow, $riskFlags, $shouldBlock, $payloadSha256, $payloadBytes, $rawPayload) {
            $status = $shouldBlock ? PdvSync::STATUS_BLOCKED : PdvSync::STATUS_QUEUED;
            $queuedAt = $shouldBlock ? null : now();

            $inserted = DB::table('pdv_syncs')->insertOrIgnore([
                'sync_id' => $syncId,
                'store_pdv_id' => $storePdvId,
                'store_id' => $storeId,
                'store_alias' => $storeAlias,
                'schema_version' => $schemaVersion,
                'event_type' => $eventType,
                'window_from' => $windowFrom->toDateTimeString(),
                'window_to' => $windowTo->toDateTimeString(),
                'agent_version' => $agentVersion,
                'agent_machine' => $agentMachine,
                'request_id' => $requestId !== '' ? $requestId : null,
                'ops_count' => $opsCount,
                'ops_loja_count' => $opsLojaCount,
                'ops_loja_ids' => json_encode($opsLojaIds, JSON_UNESCAPED_UNICODE),
                'snapshot_turnos_count' => $snapshotTurnosCount,
                'snapshot_vendas_count' => $snapshotVendasCount,
                'warnings' => json_encode($warnings, JSON_UNESCAPED_UNICODE),
                'status' => $status,
                'timestamp_skew_seconds' => $timestampSkewSeconds,
                'timestamp_out_of_window' => $timestampOutOfWindow,
                'risk_flags' => json_encode($riskFlags, JSON_UNESCAPED_UNICODE),
                'payload_sha256' => $payloadSha256,
                'payload_bytes' => $payloadBytes,
                'attempts' => 0,
                'received_at' => now(),
                'queued_at' => $queuedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sync = PdvSync::query()->where('sync_id', $syncId)->lockForUpdate()->first();
            if (!$sync || $inserted === 0) {
                return;
            }

            PdvSyncPayload::query()->create([
                'pdv_sync_id' => $sync->id,
                'payload' => $rawPayload,
                'compression' => 'none',
            ]);

            if (!$shouldBlock) {
                ProcessPdvSyncJob::dispatch($sync->id)
                    ->onQueue((string) config('pdv.queue_name', 'pdv'));
            }
        });

        if ($inserted === 0) {
            $existingSync = PdvSync::query()->where('sync_id', $syncId)->first();
            $this->pdvLog('info', 'pdv.sync.ingest', [
                'sync_id' => $syncId,
                'store_pdv_id' => $storePdvId,
                'schema_version' => $existingSync?->schema_version,
                'event_type' => $existingSync?->event_type ?? PdvSync::EVENT_TYPE_SALES,
                'request_id' => $requestId,
                'status' => 'duplicate_race',
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'pdv_sync_id' => $existingSync?->id,
                'auth_mode' => $authMode,
            ]);

            return $this->success([
                'status' => 'duplicate',
                'duplicate' => true,
                'sync_id' => $syncId,
                'pdv_sync_id' => $existingSync?->id,
                'schema_version' => $existingSync?->schema_version,
                'event_type' => $existingSync?->event_type ?? PdvSync::EVENT_TYPE_SALES,
                'request_id' => $requestId,
                'auth_mode' => $authMode,
                'message' => 'Sync already received.',
            ]);
        }

        $processingStatus = $shouldBlock ? PdvSync::STATUS_BLOCKED : PdvSync::STATUS_QUEUED;

        $this->pdvLog('info', 'pdv.sync.ingest', [
            'sync_id' => $syncId,
            'store_pdv_id' => $storePdvId,
            'schema_version' => $schemaVersion,
            'event_type' => $eventType,
            'request_id' => $requestId,
            'status' => $processingStatus,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'pdv_sync_id' => $sync?->id,
            'timestamp_out_of_window' => $timestampOutOfWindow,
            'timestamp_mode' => $timestampMode,
            'risk_flags' => $riskFlags,
            'store_alias' => $storeAlias,
            'mapped_alias' => $mappedAlias,
            'alias_mismatch' => $aliasMismatch,
            'store_resolution_status' => $storeResolution['status'] ?? null,
            'store_resolution_matched_by' => $storeResolution['matched_by'] ?? null,
            'unknown_event_type' => $unknownEventType,
            'auth_mode' => $authMode,
        ]);

        return $this->created([
            'status' => 'created',
            'processing_status' => $processingStatus,
            'duplicate' => false,
            'sync_id' => $syncId,
            'pdv_sync_id' => $sync?->id,
            'schema_version' => $schemaVersion,
            'event_type' => $eventType,
            'request_id' => $requestId,
            'timestamp_mode' => $timestampMode,
            'timestamp_out_of_window' => $timestampOutOfWindow,
            'risk_flags' => $riskFlags,
            'auth_mode' => $authMode,
            'message' => $shouldBlock
                ? 'Sync received but blocked for manual review.'
                : 'Sync received and queued for processing.',
        ]);
    }

    private function normalizeAlias(mixed $alias): ?string
    {
        if ($alias === null) {
            return null;
        }

        $value = trim((string) $alias);
        if ($value === '') {
            return null;
        }

        return strtolower($value);
    }

    private function normalizeCnpj(mixed $cnpj): ?string
    {
        if ($cnpj === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $cnpj);
        if (!is_string($digits) || $digits === '') {
            return null;
        }

        return $digits;
    }

    /**
     * @return array{0:string,1:bool}
     */
    private function normalizeEventType(mixed $eventType): array
    {
        $value = is_string($eventType) ? trim(strtolower($eventType)) : '';
        if ($value === '') {
            return [PdvSync::EVENT_TYPE_SALES, false];
        }

        $allowed = [
            PdvSync::EVENT_TYPE_SALES,
            PdvSync::EVENT_TYPE_TURNO_CLOSURE,
            PdvSync::EVENT_TYPE_MIXED,
        ];

        if (in_array($value, $allowed, true)) {
            return [$value, false];
        }

        $this->pdvLog('warning', 'pdv.sync.unknown_event_type', [
            'event_type' => $value,
        ]);

        return [PdvSync::EVENT_TYPE_SALES, true];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private function semanticEventTypeRiskFlags(array $payload, string $eventType): array
    {
        $vendas = data_get($payload, 'vendas', []);
        $turnos = data_get($payload, 'turnos', []);
        $vendasCount = is_array($vendas) ? count($vendas) : 0;
        $hasClosedTurno = false;

        if (is_array($turnos)) {
            foreach ($turnos as $turno) {
                if (!is_array($turno)) {
                    continue;
                }

                if ((bool) data_get($turno, 'fechado', false)) {
                    $hasClosedTurno = true;
                    break;
                }
            }
        }

        $riskFlags = [];
        if ($eventType === PdvSync::EVENT_TYPE_TURNO_CLOSURE && $vendasCount > 0) {
            $riskFlags[] = 'event_type_turno_closure_with_vendas';
        }
        if ($eventType === PdvSync::EVENT_TYPE_MIXED && $vendasCount === 0) {
            $riskFlags[] = 'event_type_mixed_without_vendas';
        }
        if ($eventType === PdvSync::EVENT_TYPE_MIXED && !$hasClosedTurno) {
            $riskFlags[] = 'event_type_mixed_without_closed_turno';
        }

        if ($riskFlags !== []) {
            $this->pdvLog('warning', 'pdv.sync.event_type_inconsistent', [
                'event_type' => $eventType,
                'vendas_count' => $vendasCount,
                'has_closed_turno' => $hasClosedTurno,
                'risk_flags' => $riskFlags,
            ]);
        }

        return $riskFlags;
    }

    /**
     * @param array<int, mixed> $warnings
     * @return array<int, string>
     */
    private function warningRiskFlags(array $warnings): array
    {
        $riskFlags = [];

        foreach ($warnings as $warning) {
            $value = is_string($warning)
                ? Str::upper(Str::ascii(trim($warning)))
                : '';
            if ($value === '') {
                continue;
            }

            if (str_starts_with($value, 'GESTAO_DB_FAILURE')) {
                $riskFlags[] = 'gestao_db_failure';
            }
            if (str_contains($value, 'VENDEDOR NULL')) {
                $riskFlags[] = 'vendedor_null';
            }
            if (str_contains($value, 'MEIO DE PAGAMENTO NULL')) {
                $riskFlags[] = 'meio_pagamento_null';
            }
        }

        return array_values(array_unique($riskFlags));
    }

    private function pdvValidationError(string $message, array $details, array $context = []): JsonResponse
    {
        $this->pdvLog('warning', 'pdv.sync.validation_error', array_merge($context, [
            'message' => $message,
            'details' => $details,
        ]));

        return response()->json([
            'error' => 'validation',
            'message' => $message,
            'details' => $details,
        ], 422);
    }

    private function pdvLog(string $level, string $message, array $context = []): void
    {
        $channel = (string) config('pdv.log_channel', 'stack');
        Log::channel($channel)->{$level}($message, $context);
    }
}
