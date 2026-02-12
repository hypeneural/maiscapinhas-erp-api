<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pdv\PdvSyncIngestRequest;
use App\Http\Traits\ApiResponse;
use App\Jobs\ProcessPdvSyncJob;
use App\Models\PdvStoreMapping;
use App\Models\PdvSync;
use App\Models\PdvSyncPayload;
use App\Support\Audit\AuditContext;
use App\Support\Pdv\PdvDateTime;
use App\Support\Pdv\PdvJsonSchemaValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PdvSyncController extends Controller
{
    use ApiResponse;

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

        // Idempotency first: duplicates are always accepted (200).
        $existingSync = PdvSync::query()->where('sync_id', $syncId)->first();
        if ($existingSync) {
            Log::info('pdv.sync.ingest', [
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
        $supportedSchemaVersions = config('pdv.supported_schema_versions', ['2.0']);
        $supportedSchemaVersions = is_array($supportedSchemaVersions) ? $supportedSchemaVersions : ['2.0'];

        if ($headerSchemaVersion !== '' && !in_array($headerSchemaVersion, $supportedSchemaVersions, true)) {
            return $this->pdvValidationError(
                'Unsupported schema version informed in header.',
                [
                    'X-PDV-Schema-Version' => [
                        'Unsupported schema version in header.',
                    ],
                ]
            );
        }

        if ($headerSchemaVersion !== '' && $headerSchemaVersion !== $schemaVersion) {
            return $this->pdvValidationError(
                'Schema version header does not match payload.',
                [
                    'schema_version' => [
                        'X-PDV-Schema-Version must match payload schema_version.',
                    ],
                ]
            );
        }

        $schemaValidationPayload = is_array($rawPayloadArray) ? $rawPayloadArray : $payload;
        $schemaValidation = app(PdvJsonSchemaValidator::class)->validate($schemaValidationPayload);
        if ($schemaValidation['status'] === 'invalid') {
            return $this->pdvValidationError(
                'Payload does not match JSON schema.',
                [
                    'schema' => $schemaValidation['errors'],
                ]
            );
        }

        if ($schemaValidation['status'] === 'error') {
            Log::error('pdv.sync.schema_validation', [
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

        $rawPayload = $request->getContent();
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
            Log::warning('pdv.sync.ingest', [
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
                ]
            );
        }

        $storeMapping = PdvStoreMapping::query()
            ->where('pdv_store_id', $storePdvId)
            ->where('active', true)
            ->first();

        $storeId = $storeMapping?->store_id;
        $storeAlias = $this->normalizeAlias(data_get($payload, 'store.alias'));
        $mappedAlias = $this->normalizeAlias($storeMapping?->alias);
        $aliasMismatch = $storeMapping !== null
            && $storeAlias !== null
            && $mappedAlias !== null
            && $storeAlias !== $mappedAlias;
        $blockOnAliasMismatch = (bool) config('pdv.block_on_alias_mismatch', false);
        $shouldBlock = $aliasMismatch && $blockOnAliasMismatch;

        $riskFlags = [];
        if ($timestampOutOfWindow) {
            $riskFlags[] = 'timestamp_out_of_window';
        }
        if ($headerTimestamp === null && $authMode === 'hmac') {
            $riskFlags[] = 'timestamp_missing';
        }
        if ($storeId === null) {
            $riskFlags[] = 'store_mapping_missing';
        }
        if ($aliasMismatch) {
            $riskFlags[] = 'store_alias_mismatch';
        }
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
            $this->semanticEventTypeRiskFlags($payload, $eventType)
        )));

        $warnings = data_get($payload, 'integrity.warnings', []);
        $warnings = is_array($warnings) ? array_values($warnings) : [];

        $windowFrom = PdvDateTime::parseToUtc((string) data_get($payload, 'window.from'));
        $windowTo = PdvDateTime::parseToUtc((string) data_get($payload, 'window.to'));
        if ($windowFrom === null || $windowTo === null) {
            return $this->pdvValidationError(
                'Invalid window datetime format.',
                [
                    'window' => [
                        'window.from and window.to must be valid datetimes.',
                    ],
                ]
            );
        }

        $agentVersion = data_get($payload, 'agent.version');
        $agentMachine = data_get($payload, 'agent.machine');
        $opsCount = (int) data_get($payload, 'ops.count', 0);
        $opsLojaCount = (int) data_get($payload, 'ops.loja_count', 0);
        $opsLojaIdsRaw = data_get($payload, 'ops.loja_ids', []);
        $opsLojaIds = is_array($opsLojaIdsRaw) ? array_values(array_filter(array_map(
            static fn (mixed $value): int => (int) $value,
            $opsLojaIdsRaw
        ), static fn (int $value): bool => $value > 0)) : [];
        $snapshotTurnosRaw = data_get($payload, 'snapshot_turnos', []);
        $snapshotTurnosCount = is_array($snapshotTurnosRaw) ? count($snapshotTurnosRaw) : 0;
        $snapshotVendasRaw = data_get($payload, 'snapshot_vendas', []);
        $snapshotVendasCount = is_array($snapshotVendasRaw) ? count($snapshotVendasRaw) : 0;

        $inserted = 0;
        $sync = null;

        DB::transaction(function () use (
            &$inserted,
            &$sync,
            $syncId,
            $storePdvId,
            $storeId,
            $storeAlias,
            $schemaVersion,
            $eventType,
            $requestId,
            $windowFrom,
            $windowTo,
            $agentVersion,
            $agentMachine,
            $opsCount,
            $opsLojaCount,
            $opsLojaIds,
            $snapshotTurnosCount,
            $snapshotVendasCount,
            $warnings,
            $timestampSkewSeconds,
            $timestampOutOfWindow,
            $riskFlags,
            $shouldBlock,
            $payloadSha256,
            $payloadBytes,
            $rawPayload
        ) {
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
            Log::info('pdv.sync.ingest', [
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

        Log::info('pdv.sync.ingest', [
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

        Log::warning('pdv.sync.unknown_event_type', [
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
            Log::warning('pdv.sync.event_type_inconsistent', [
                'event_type' => $eventType,
                'vendas_count' => $vendasCount,
                'has_closed_turno' => $hasClosedTurno,
                'risk_flags' => $riskFlags,
            ]);
        }

        return $riskFlags;
    }

    private function pdvValidationError(string $message, array $details): JsonResponse
    {
        return response()->json([
            'error' => 'validation',
            'message' => $message,
            'details' => $details,
        ], 422);
    }
}
