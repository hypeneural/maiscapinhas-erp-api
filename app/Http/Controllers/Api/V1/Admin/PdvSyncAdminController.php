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

class PdvSyncAdminController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'max:20'],
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
                'request_id' => $sync->request_id,
                'store_pdv_id' => $sync->store_pdv_id,
                'store_id' => $sync->store_id,
                'status' => $sync->status,
                'ops_count' => $sync->ops_count,
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

        $thresholdMinutes = (int) ($validated['minutes_without_sync'] ?? 20);
        $threshold = now()->subMinutes($thresholdMinutes);

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

        $last24hStart = now()->subDay();
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
            ->map(function ($row) use ($threshold) {
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
                    'minutes_since_last_sync' => $lastReceived ? $lastReceived->diffInMinutes(now()) : null,
                    'stale' => $isStale,
                ];
            })
            ->values();

        $staleStores = $storeHealth->where('stale', true)->values();

        return $this->success([
            'backlog_by_status' => $statusCounts,
            'status_breakdown' => $statusBreakdown,
            'risk_flags' => $riskFlagCounts,
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
