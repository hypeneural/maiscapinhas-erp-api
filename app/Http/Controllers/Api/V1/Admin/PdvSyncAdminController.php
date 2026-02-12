<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\PdvSync;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PdvSyncAdminController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'max:20'],
            'event_type' => ['sometimes', 'string', 'max:30'],
            'sync_id' => ['sometimes', 'string', 'max:128'],
            'schema_version' => ['sometimes', 'string', 'max:10'],
            'request_id' => ['sometimes', 'string', 'max:64'],
            'risk_flag' => ['sometimes', 'string', 'max:80'],
            'store_pdv_id' => ['sometimes', 'integer', 'min:1'],
            'store_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PdvSync::query();

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['event_type'])) {
            $query->where('event_type', $validated['event_type']);
        }

        if (!empty($validated['sync_id'])) {
            $query->where('sync_id', 'like', '%' . $validated['sync_id'] . '%');
        }

        if (!empty($validated['schema_version'])) {
            $query->where('schema_version', $validated['schema_version']);
        }

        if (!empty($validated['request_id'])) {
            $query->where('request_id', 'like', '%' . $validated['request_id'] . '%');
        }

        if (!empty($validated['risk_flag'])) {
            $query->whereJsonContains('risk_flags', $validated['risk_flag']);
        }

        if (!empty($validated['store_pdv_id'])) {
            $query->where('store_pdv_id', (int) $validated['store_pdv_id']);
        }

        if (!empty($validated['store_id'])) {
            $query->where('store_id', (int) $validated['store_id']);
        }

        if (!empty($validated['from'])) {
            $query->where('received_at', '>=', CarbonImmutable::parse($validated['from'])->startOfDay());
        }

        if (!empty($validated['to'])) {
            $query->where('received_at', '<=', CarbonImmutable::parse($validated['to'])->endOfDay());
        }

        $perPage = (int) ($validated['per_page'] ?? 25);
        $syncs = $query->orderByDesc('id')->paginate($perPage);

        $rows = collect($syncs->items())->map(function (PdvSync $sync): array {
            $queueDelayMs = null;
            if ($sync->received_at && $sync->processing_started_at) {
                $queueDelayMs = (int) $sync->processing_started_at->diffInMilliseconds($sync->received_at);
            }

            $processingMs = null;
            if ($sync->processing_started_at && $sync->processed_at) {
                $processingMs = (int) $sync->processed_at->diffInMilliseconds($sync->processing_started_at);
            }

            $endToEndMs = null;
            if ($sync->received_at && $sync->processed_at) {
                $endToEndMs = (int) $sync->processed_at->diffInMilliseconds($sync->received_at);
            }

            return [
                'id' => $sync->id,
                'sync_id' => $sync->sync_id,
                'schema_version' => $sync->schema_version,
                'event_type' => $sync->event_type ?? PdvSync::EVENT_TYPE_SALES,
                'request_id' => $sync->request_id,
                'store_pdv_id' => $sync->store_pdv_id,
                'store_id' => $sync->store_id,
                'status' => $sync->status,
                'ops_count' => $sync->ops_count,
                'ops_loja_count' => (int) ($sync->ops_loja_count ?? 0),
                'ops_loja_ids' => is_array($sync->ops_loja_ids) ? $sync->ops_loja_ids : [],
                'snapshot_turnos_count' => (int) ($sync->snapshot_turnos_count ?? 0),
                'snapshot_vendas_count' => (int) ($sync->snapshot_vendas_count ?? 0),
                'attempts' => $sync->attempts,
                'timestamp_out_of_window' => (bool) $sync->timestamp_out_of_window,
                'risk_flags' => $sync->risk_flags ?? [],
                'window_from' => $sync->window_from?->toIso8601String(),
                'window_to' => $sync->window_to?->toIso8601String(),
                'received_at' => $sync->received_at?->toIso8601String(),
                'processing_started_at' => $sync->processing_started_at?->toIso8601String(),
                'processed_at' => $sync->processed_at?->toIso8601String(),
                'queue_delay_ms' => $queueDelayMs,
                'processing_ms' => $processingMs,
                'end_to_end_ms' => $endToEndMs,
                'last_error' => $sync->last_error,
            ];
        })->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'request_id' => app(\App\Support\Audit\AuditContext::class)->getRequestId(),
                'timestamp' => now()->toIso8601String(),
                'pagination' => [
                    'total' => $syncs->total(),
                    'per_page' => $syncs->perPage(),
                    'current_page' => $syncs->currentPage(),
                    'last_page' => $syncs->lastPage(),
                    'from' => $syncs->firstItem(),
                    'to' => $syncs->lastItem(),
                ],
            ],
        ]);
    }

    public function metrics(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'minutes_without_sync' => ['sometimes', 'integer', 'min:5', 'max:1440'],
        ]);

        $defaultThresholdMinutes = max(5, (int) config('pdv.monitor_silent_store_threshold_minutes', 120));
        $thresholdMinutes = (int) ($validated['minutes_without_sync'] ?? $defaultThresholdMinutes);
        $maxStaleStores = max(0, (int) config('pdv.monitor_max_stale_stores', 0));
        $now = CarbonImmutable::now();
        $threshold = $now->subMinutes($thresholdMinutes);

        $statusCounts = PdvSync::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $riskFlagCounts = [
            'store_mapping_missing' => (int) PdvSync::query()
                ->whereJsonContains('risk_flags', 'store_mapping_missing')
                ->count(),
            'user_mapping_missing' => (int) PdvSync::query()
                ->whereJsonContains('risk_flags', 'user_mapping_missing')
                ->count(),
            'auth_bearer_fallback' => (int) PdvSync::query()
                ->whereJsonContains('risk_flags', 'auth_bearer_fallback')
                ->count(),
            'timestamp_out_of_window' => (int) PdvSync::query()
                ->whereJsonContains('risk_flags', 'timestamp_out_of_window')
                ->count(),
            'store_alias_mismatch' => (int) PdvSync::query()
                ->whereJsonContains('risk_flags', 'store_alias_mismatch')
                ->count(),
            'store_alias_mismatch_blocked' => (int) PdvSync::query()
                ->whereJsonContains('risk_flags', 'store_alias_mismatch_blocked')
                ->count(),
            'event_type_turno_closure_with_vendas' => (int) PdvSync::query()
                ->whereJsonContains('risk_flags', 'event_type_turno_closure_with_vendas')
                ->count(),
            'event_type_mixed_without_vendas' => (int) PdvSync::query()
                ->whereJsonContains('risk_flags', 'event_type_mixed_without_vendas')
                ->count(),
            'event_type_mixed_without_closed_turno' => (int) PdvSync::query()
                ->whereJsonContains('risk_flags', 'event_type_mixed_without_closed_turno')
                ->count(),
            'gestao_db_failure' => (int) PdvSync::query()
                ->whereJsonContains('risk_flags', 'gestao_db_failure')
                ->count(),
        ];

        $statusBreakdown = [
            'queued' => (int) ($statusCounts[PdvSync::STATUS_QUEUED] ?? 0),
            'processing' => (int) ($statusCounts[PdvSync::STATUS_PROCESSING] ?? 0),
            'processed' => (int) ($statusCounts[PdvSync::STATUS_PROCESSED] ?? 0),
            'failed' => (int) ($statusCounts[PdvSync::STATUS_FAILED] ?? 0),
            'blocked' => (int) ($statusCounts[PdvSync::STATUS_BLOCKED] ?? 0),
            // Duplicates are acknowledged at ingest level and are not persisted as a status row.
            'duplicate' => 0,
        ];

        $last24hStart = $now->subDay();
        $total24h = PdvSync::query()
            ->where('received_at', '>=', $last24hStart)
            ->count();
        $failed24h = PdvSync::query()
            ->where('received_at', '>=', $last24hStart)
            ->where('status', PdvSync::STATUS_FAILED)
            ->count();

        $failureRate24h = $total24h > 0
            ? round(($failed24h / $total24h) * 100, 2)
            : 0.0;

        $statusCounts24h = PdvSync::query()
            ->where('received_at', '>=', $last24hStart)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $statusBreakdown24h = [
            'queued' => (int) ($statusCounts24h[PdvSync::STATUS_QUEUED] ?? 0),
            'processing' => (int) ($statusCounts24h[PdvSync::STATUS_PROCESSING] ?? 0),
            'processed' => (int) ($statusCounts24h[PdvSync::STATUS_PROCESSED] ?? 0),
            'failed' => (int) ($statusCounts24h[PdvSync::STATUS_FAILED] ?? 0),
            'blocked' => (int) ($statusCounts24h[PdvSync::STATUS_BLOCKED] ?? 0),
            'duplicate' => 0,
        ];

        $eventTypeCounts = PdvSync::query()
            ->selectRaw('event_type, COUNT(*) as total')
            ->groupBy('event_type')
            ->pluck('total', 'event_type')
            ->toArray();

        $eventTypeBreakdown = [
            PdvSync::EVENT_TYPE_SALES => (int) ($eventTypeCounts[PdvSync::EVENT_TYPE_SALES] ?? 0),
            PdvSync::EVENT_TYPE_TURNO_CLOSURE => (int) ($eventTypeCounts[PdvSync::EVENT_TYPE_TURNO_CLOSURE] ?? 0),
            PdvSync::EVENT_TYPE_MIXED => (int) ($eventTypeCounts[PdvSync::EVENT_TYPE_MIXED] ?? 0),
        ];

        $unknownEventTypeCount = (int) PdvSync::query()
            ->whereNotIn('event_type', [
                PdvSync::EVENT_TYPE_SALES,
                PdvSync::EVENT_TYPE_TURNO_CLOSURE,
                PdvSync::EVENT_TYPE_MIXED,
            ])
            ->count();

        if ($unknownEventTypeCount > 0) {
            $eventTypeBreakdown['unknown'] = $unknownEventTypeCount;
        }

        $schemaVersionCounts = PdvSync::query()
            ->selectRaw('schema_version, COUNT(*) as total')
            ->groupBy('schema_version')
            ->pluck('total', 'schema_version')
            ->toArray();

        $schemaVersionBreakdown = [];
        foreach ($schemaVersionCounts as $schemaVersion => $count) {
            $key = trim((string) $schemaVersion);
            $key = $key !== '' ? $key : 'unknown';
            $schemaVersionBreakdown[$key] = (int) $count;
        }

        if ($schemaVersionBreakdown === []) {
            $schemaVersionBreakdown['unknown'] = 0;
        }

        $canalBreakdown = [
            'HIPER_CAIXA' => 0,
            'HIPER_LOJA' => 0,
        ];
        $canalSource = 'unavailable';

        if (Schema::hasTable('pdv_vendas') && Schema::hasColumn('pdv_vendas', 'canal')) {
            $canalSource = 'pdv_vendas';
            $rawCanalCounts = DB::table('pdv_vendas')
                ->selectRaw('canal, COUNT(*) as total')
                ->groupBy('canal')
                ->pluck('total', 'canal')
                ->toArray();

            foreach ($rawCanalCounts as $canal => $count) {
                $normalizedCanal = strtoupper(str_replace('-', '_', trim((string) $canal)));

                if ($normalizedCanal === 'HIPER_CAIXA' || $normalizedCanal === 'HIPER_LOJA') {
                    $canalBreakdown[$normalizedCanal] = (int) $count;
                    continue;
                }

                $canalBreakdown['unknown'] = (int) ($canalBreakdown['unknown'] ?? 0) + (int) $count;
            }
        }

        $samples = PdvSync::query()
            ->whereNotNull('processing_started_at')
            ->whereNotNull('processed_at')
            ->latest('processed_at')
            ->limit(1000)
            ->get(['processing_started_at', 'processed_at', 'received_at']);

        $avgQueueDelayMs = (int) round(
            $samples
                ->filter(fn (PdvSync $s) => $s->received_at && $s->processing_started_at)
                ->map(fn (PdvSync $s) => $s->processing_started_at->diffInMilliseconds($s->received_at))
                ->avg() ?? 0
        );

        $avgProcessingMs = (int) round(
            $samples
                ->map(fn (PdvSync $s) => $s->processed_at->diffInMilliseconds($s->processing_started_at))
                ->avg() ?? 0
        );

        $storeHealth = collect();
        if (Schema::hasTable('pdv_store_mappings') && Schema::hasTable('stores')) {
            $latestByStore = DB::table('pdv_syncs')
                ->select('store_pdv_id', DB::raw('MAX(received_at) as last_received_at'))
                ->groupBy('store_pdv_id');

            $storeHealth = DB::table('pdv_store_mappings as m')
                ->leftJoinSub($latestByStore, 'ls', function ($join) {
                    $join->on('ls.store_pdv_id', '=', 'm.pdv_store_id');
                })
                ->leftJoin('stores as s', 's.id', '=', 'm.store_id')
                ->where('m.active', true)
                ->select([
                    'm.pdv_store_id',
                    'm.store_id',
                    'm.alias',
                    's.name as store_name',
                    'ls.last_received_at',
                ])
                ->get()
                ->map(function ($row) use ($threshold, $now) {
                    $lastReceived = $row->last_received_at
                        ? CarbonImmutable::parse((string) $row->last_received_at)
                        : null;
                    $isStale = $lastReceived === null || $lastReceived->lt($threshold);

                    return [
                        'store_pdv_id' => (int) $row->pdv_store_id,
                        'store_id' => $row->store_id !== null ? (int) $row->store_id : null,
                        'alias' => $row->alias,
                        'store_name' => $row->store_name,
                        'last_received_at' => $lastReceived?->toIso8601String(),
                        'minutes_since_last_sync' => $lastReceived ? $lastReceived->diffInMinutes($now) : null,
                        'stale' => $isStale,
                    ];
                })
                ->values();
        }

        $staleStores = $storeHealth->where('stale', true)->values();

        $snapshotMetrics = [
            'available' => false,
            'turnos_processed_total' => null,
            'vendas_processed_total' => null,
            'turnos_processed_last_24h' => null,
            'vendas_processed_last_24h' => null,
            'syncs_with_snapshots_total' => null,
        ];

        if (Schema::hasColumn('pdv_syncs', 'snapshot_turnos_count')
            && Schema::hasColumn('pdv_syncs', 'snapshot_vendas_count')) {
            $snapshotMetrics['available'] = true;
            $snapshotMetrics['turnos_processed_total'] = (int) PdvSync::query()->sum('snapshot_turnos_count');
            $snapshotMetrics['vendas_processed_total'] = (int) PdvSync::query()->sum('snapshot_vendas_count');
            $snapshotMetrics['turnos_processed_last_24h'] = (int) PdvSync::query()
                ->where('received_at', '>=', $last24hStart)
                ->sum('snapshot_turnos_count');
            $snapshotMetrics['vendas_processed_last_24h'] = (int) PdvSync::query()
                ->where('received_at', '>=', $last24hStart)
                ->sum('snapshot_vendas_count');
            $snapshotMetrics['syncs_with_snapshots_total'] = (int) PdvSync::query()
                ->where(function ($query): void {
                    $query->where('snapshot_turnos_count', '>', 0)
                        ->orWhere('snapshot_vendas_count', '>', 0);
                })
                ->count();
        }

        return $this->success([
            'backlog_by_status' => $statusCounts,
            'status_breakdown' => $statusBreakdown,
            'risk_flags' => $riskFlagCounts,
            'by_event_type' => $eventTypeBreakdown,
            'by_schema_version' => $schemaVersionBreakdown,
            'by_canal' => [
                'source' => $canalSource,
                'totals' => $canalBreakdown,
            ],
            'snapshots' => $snapshotMetrics,
            'last_24h' => [
                'total' => $total24h,
                'failed' => $failed24h,
                'failure_rate_percent' => $failureRate24h,
                'status_breakdown' => $statusBreakdown24h,
            ],
            'latency' => [
                'avg_queue_delay_ms' => $avgQueueDelayMs,
                'avg_processing_ms' => $avgProcessingMs,
            ],
            'stores' => [
                'threshold_minutes_without_sync' => $thresholdMinutes,
                'max_stale_stores' => $maxStaleStores,
                'active_mapped_stores' => $storeHealth->count(),
                'stale_count' => $staleStores->count(),
                'stale' => $staleStores->all(),
            ],
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if (!$user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->isSuperAdmin()) {
            return;
        }

        $isAdmin = $user->storeUsers()->where('role', 'admin')->exists();
        if (!$isAdmin) {
            abort(403, 'Apenas administradores podem acessar este recurso.');
        }
    }
}
