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
        $syncId = (string) data_get($payload, 'integrity.sync_id');
        $storePdvId = (int) data_get($payload, 'store.id_ponto_venda');
        $schemaVersion = (string) data_get($payload, 'schema_version');
        $authMode = (string) $request->attributes->get('pdv_auth_mode', 'unknown');
        $requestId = app(AuditContext::class)->getRequestId() ?: (string) $request->header('X-Request-Id', '');

        // Idempotency first: duplicates are always accepted (200).
        $existingSync = PdvSync::query()->where('sync_id', $syncId)->first();
        if ($existingSync) {
            Log::info('pdv.sync.ingest', [
                'sync_id' => $syncId,
                'store_pdv_id' => $storePdvId,
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
                'request_id' => $requestId,
                'auth_mode' => $authMode,
                'message' => 'Sync already received.',
            ]);
        }

        $headerSchemaVersion = trim((string) $request->header('X-PDV-Schema-Version', ''));
        $supportedSchemaVersions = config('pdv.supported_schema_versions', ['2.0']);
        $supportedSchemaVersions = is_array($supportedSchemaVersions) ? $supportedSchemaVersions : ['2.0'];

        if ($headerSchemaVersion !== '' && !in_array($headerSchemaVersion, $supportedSchemaVersions, true)) {
            return $this->error(
                'Unsupported schema version informed in header.',
                422,
                [
                    'X-PDV-Schema-Version' => [
                        'Unsupported schema version in header.',
                    ],
                ]
            );
        }

        if ($headerSchemaVersion !== '' && $headerSchemaVersion !== $schemaVersion) {
            return $this->error(
                'Schema version header does not match payload.',
                422,
                [
                    'schema_version' => [
                        'X-PDV-Schema-Version must match payload schema_version.',
                    ],
                ]
            );
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
                'status' => 'rejected_strict_timestamp',
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'timestamp_skew_seconds' => $timestampSkewSeconds,
                'auth_mode' => $authMode,
            ]);

            return $this->error(
                'Timestamp is outside the accepted window for new syncs.',
                422,
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
        if ($authMode === 'bearer_fallback') {
            $riskFlags[] = 'auth_bearer_fallback';
        }

        $warnings = data_get($payload, 'integrity.warnings', []);
        $warnings = is_array($warnings) ? array_values($warnings) : [];

        $windowFrom = PdvDateTime::parseToUtc((string) data_get($payload, 'window.from'));
        $windowTo = PdvDateTime::parseToUtc((string) data_get($payload, 'window.to'));
        if ($windowFrom === null || $windowTo === null) {
            return $this->error(
                'Invalid window datetime format.',
                422,
                [
                    'window' => [
                        'window.from and window.to must be valid datetimes.',
                    ],
                ]
            );
        }

        $agentVersion = data_get($payload, 'agent.version');
        $agentMachine = data_get($payload, 'agent.machine');
        $storeAlias = data_get($payload, 'store.alias');
        $opsCount = (int) data_get($payload, 'ops.count', 0);

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
            $requestId,
            $windowFrom,
            $windowTo,
            $agentVersion,
            $agentMachine,
            $opsCount,
            $warnings,
            $timestampSkewSeconds,
            $timestampOutOfWindow,
            $riskFlags,
            $payloadSha256,
            $payloadBytes,
            $rawPayload
        ) {
            $inserted = DB::table('pdv_syncs')->insertOrIgnore([
                'sync_id' => $syncId,
                'store_pdv_id' => $storePdvId,
                'store_id' => $storeId,
                'store_alias' => $storeAlias,
                'schema_version' => $schemaVersion,
                'window_from' => $windowFrom->toDateTimeString(),
                'window_to' => $windowTo->toDateTimeString(),
                'agent_version' => $agentVersion,
                'agent_machine' => $agentMachine,
                'request_id' => $requestId !== '' ? $requestId : null,
                'ops_count' => $opsCount,
                'warnings' => json_encode($warnings, JSON_UNESCAPED_UNICODE),
                'status' => PdvSync::STATUS_QUEUED,
                'timestamp_skew_seconds' => $timestampSkewSeconds,
                'timestamp_out_of_window' => $timestampOutOfWindow,
                'risk_flags' => json_encode($riskFlags, JSON_UNESCAPED_UNICODE),
                'payload_sha256' => $payloadSha256,
                'payload_bytes' => $payloadBytes,
                'attempts' => 0,
                'received_at' => now(),
                'queued_at' => now(),
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

            ProcessPdvSyncJob::dispatch($sync->id)
                ->onQueue((string) config('pdv.queue_name', 'pdv'));
        });

        if ($inserted === 0) {
            $existingSync = PdvSync::query()->where('sync_id', $syncId)->first();
            Log::info('pdv.sync.ingest', [
                'sync_id' => $syncId,
                'store_pdv_id' => $storePdvId,
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
                'request_id' => $requestId,
                'auth_mode' => $authMode,
                'message' => 'Sync already received.',
            ]);
        }

        Log::info('pdv.sync.ingest', [
            'sync_id' => $syncId,
            'store_pdv_id' => $storePdvId,
            'status' => 'queued',
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'pdv_sync_id' => $sync?->id,
            'timestamp_out_of_window' => $timestampOutOfWindow,
            'timestamp_mode' => $timestampMode,
            'risk_flags' => $riskFlags,
            'auth_mode' => $authMode,
        ]);

        return $this->created([
            'status' => 'created',
            'processing_status' => 'queued',
            'duplicate' => false,
            'sync_id' => $syncId,
            'pdv_sync_id' => $sync?->id,
            'schema_version' => $schemaVersion,
            'request_id' => $requestId,
            'timestamp_mode' => $timestampMode,
            'timestamp_out_of_window' => $timestampOutOfWindow,
            'risk_flags' => $riskFlags,
            'auth_mode' => $authMode,
            'message' => 'Sync received and queued for processing.',
        ]);
    }
}
