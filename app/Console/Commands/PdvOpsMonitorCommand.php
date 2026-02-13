<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PdvSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PdvOpsMonitorCommand extends Command
{
    protected $signature = 'pdv:ops-monitor
                            {--json : Output as JSON}
                            {--force-alert : Ignore cooldown and force external alerts}';

    protected $description = 'Monitor PDV queue health and send operational alerts when thresholds are exceeded.';

    public function handle(): int
    {
        if (!(bool) config('pdv.monitor_enabled', true)) {
            $data = [
                'status' => 'disabled',
                'message' => 'PDV monitor is disabled by configuration.',
                'timestamp' => now()->toIso8601String(),
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->warn($data['message']);
            }

            return self::SUCCESS;
        }

        $queueName = (string) config('pdv.queue_name', 'pdv');
        $silentStoreThresholdMinutes = max(5, (int) config('pdv.monitor_silent_store_threshold_minutes', 120));
        $thresholds = [
            'queue_backlog' => max(0, (int) config('pdv.monitor_max_queue_backlog', 3)),
            'queued_syncs' => max(0, (int) config('pdv.monitor_max_queued_syncs', 5)),
            'failed_jobs' => max(0, (int) config('pdv.monitor_max_failed_jobs', 0)),
            'stale_stores' => max(0, (int) config('pdv.monitor_max_stale_stores', 0)),
            'gestao_db_failures_30m' => max(0, (int) config('pdv.monitor_max_gestao_db_failures_30m', 3)),
        ];
        $now = CarbonImmutable::now();
        $staleStores = $this->getStaleStores($silentStoreThresholdMinutes, $now);
        $identityMetrics = $this->getIdentityResolutionMetrics($now);

        $metrics = [
            'queue_backlog' => $this->getQueueBacklog($queueName),
            'queued_syncs' => $this->getQueuedSyncs(),
            'failed_jobs' => $this->getFailedJobsCount(),
            'queue_name' => $queueName,
            'silent_store_threshold_minutes' => $silentStoreThresholdMinutes,
            'stale_stores_available' => $staleStores['available'],
            'active_mapped_stores' => $staleStores['active_mapped_stores'],
            'stale_stores_count' => $staleStores['stale_count'],
            'stale_stores' => $staleStores['stores'],
            'gestao_db_failures_30m' => $this->getGestaoDbFailuresLast30Minutes(),
            'identity_resolution' => $identityMetrics,
            'store_resolution_cnpj_rate' => $identityMetrics['store_resolution_cnpj_rate_percent'] ?? null,
            'user_resolution_login_rate' => $identityMetrics['user_resolution_login_rate_percent'] ?? null,
        ];

        $issues = $this->buildIssues($metrics, $thresholds);
        $status = $issues === [] ? 'ok' : 'alert';
        $payload = [
            'status' => $status,
            'timestamp' => $now->toIso8601String(),
            'app' => [
                'name' => (string) config('app.name', 'Laravel'),
                'env' => (string) config('app.env', 'production'),
                'url' => (string) config('app.url', ''),
            ],
            'metrics' => $metrics,
            'thresholds' => $thresholds,
            'issues' => $issues,
        ];

        $notification = [
            'sent' => false,
            'suppressed' => false,
            'reason' => null,
            'channels' => [],
            'resolved' => false,
        ];

        if ($status === 'alert') {
            $notification = $this->notifyAlert($payload, (bool) $this->option('force-alert'));
            Log::error('pdv.monitor.alert', array_merge($payload, ['notification' => $notification]));
        } else {
            $notification = $this->notifyRecoveryIfNeeded($payload, (bool) $this->option('force-alert'));
            Log::info('pdv.monitor.ok', array_merge($payload, ['notification' => $notification]));
        }

        $output = array_merge($payload, ['notification' => $notification]);
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf(
                '[PDV Monitor] status=%s queue_backlog=%s queued_syncs=%d failed_jobs=%s stale_stores=%s',
                strtoupper($status),
                $metrics['queue_backlog'] === null ? 'n/a' : (string) $metrics['queue_backlog'],
                $metrics['queued_syncs'],
                $metrics['failed_jobs'] === null ? 'n/a' : (string) $metrics['failed_jobs'],
                (bool) $metrics['stale_stores_available']
                    ? (string) $metrics['stale_stores_count']
                    : 'n/a'
            ));

            if ($issues !== []) {
                foreach ($issues as $issue) {
                    $this->error(sprintf(
                        '- %s (value=%s threshold=%s)',
                        $issue['name'],
                        (string) $issue['value'],
                        (string) $issue['threshold']
                    ));
                }

                if ($notification['sent']) {
                    $this->info('External alert sent.');
                } elseif ($notification['suppressed']) {
                    $this->warn('Alert suppressed by cooldown.');
                } else {
                    $this->warn('No external alert channel configured.');
                }
            }
        }

        return $status === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int, array{name:string,value:int,threshold:int,severity:string}>
     */
    private function buildIssues(array $metrics, array $thresholds): array
    {
        $issues = [];

        $queueBacklog = $metrics['queue_backlog'];
        if ($queueBacklog === null) {
            $issues[] = [
                'name' => 'queue_backlog_unavailable',
                'value' => -1,
                'threshold' => $thresholds['queue_backlog'],
                'severity' => 'critical',
            ];
        } elseif ($queueBacklog > $thresholds['queue_backlog']) {
            $issues[] = [
                'name' => 'queue_backlog_high',
                'value' => $queueBacklog,
                'threshold' => $thresholds['queue_backlog'],
                'severity' => 'critical',
            ];
        }

        $queuedSyncs = (int) $metrics['queued_syncs'];
        if ($queuedSyncs > $thresholds['queued_syncs']) {
            $issues[] = [
                'name' => 'queued_syncs_high',
                'value' => $queuedSyncs,
                'threshold' => $thresholds['queued_syncs'],
                'severity' => 'critical',
            ];
        }

        $failedJobs = $metrics['failed_jobs'];
        if ($failedJobs === null) {
            $issues[] = [
                'name' => 'failed_jobs_unavailable',
                'value' => -1,
                'threshold' => $thresholds['failed_jobs'],
                'severity' => 'warning',
            ];
        } elseif ($failedJobs > $thresholds['failed_jobs']) {
            $issues[] = [
                'name' => 'failed_jobs_high',
                'value' => $failedJobs,
                'threshold' => $thresholds['failed_jobs'],
                'severity' => 'critical',
            ];
        }

        $staleStoresAvailable = (bool) ($metrics['stale_stores_available'] ?? false);
        $staleStoresCount = (int) ($metrics['stale_stores_count'] ?? 0);
        if ($staleStoresAvailable && $staleStoresCount > $thresholds['stale_stores']) {
            $issues[] = [
                'name' => 'stale_stores_high',
                'value' => $staleStoresCount,
                'threshold' => $thresholds['stale_stores'],
                'severity' => 'critical',
            ];
        }

        $gestaoDbFailures = $metrics['gestao_db_failures_30m'] ?? null;
        if ($gestaoDbFailures !== null && (int) $gestaoDbFailures > $thresholds['gestao_db_failures_30m']) {
            $issues[] = [
                'name' => 'gestao_db_failure_high',
                'value' => (int) $gestaoDbFailures,
                'threshold' => $thresholds['gestao_db_failures_30m'],
                'severity' => 'warning',
            ];
        }

        return $issues;
    }

    private function getQueueBacklog(string $queueName): ?int
    {
        try {
            $connectionName = (string) config('queue.default', 'redis');
            $size = Queue::connection($connectionName)->size($queueName);

            return max(0, (int) $size);
        } catch (Throwable $e) {
            Log::warning('pdv.monitor.queue_backlog_unavailable', [
                'queue_name' => $queueName,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function getQueuedSyncs(): int
    {
        try {
            return (int) PdvSync::query()
                ->where('status', PdvSync::STATUS_QUEUED)
                ->count();
        } catch (Throwable $e) {
            Log::warning('pdv.monitor.queued_syncs_unavailable', [
                'message' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    private function getFailedJobsCount(): ?int
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');
        if ($table === '' || !Schema::hasTable($table)) {
            return null;
        }

        try {
            return (int) DB::table($table)->count();
        } catch (Throwable $e) {
            Log::warning('pdv.monitor.failed_jobs_unavailable', [
                'table' => $table,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function getGestaoDbFailuresLast30Minutes(): ?int
    {
        if (!Schema::hasTable('pdv_syncs') || !Schema::hasColumn('pdv_syncs', 'risk_flags')) {
            return null;
        }

        try {
            return (int) PdvSync::query()
                ->where('received_at', '>=', now()->subMinutes(30))
                ->whereJsonContains('risk_flags', 'gestao_db_failure')
                ->count();
        } catch (Throwable $e) {
            Log::warning('pdv.monitor.gestao_db_failures_unavailable', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{
     *   available:bool,
     *   window_minutes:int,
     *   total_syncs:int,
     *   store_mapping_missing_count:int,
     *   store_mapping_ambiguous_count:int,
     *   store_mapping_by_id_fallback_count:int,
     *   store_resolution_cnpj_rate_percent:float|null,
     *   user_mapping_missing_count:int,
     *   user_login_missing_count:int,
     *   user_mapping_by_id_fallback_count:int,
     *   user_login_mismatch_count:int,
     *   user_resolution_login_rate_percent:float|null
     * }
     */
    private function getIdentityResolutionMetrics(CarbonImmutable $now): array
    {
        if (!Schema::hasTable('pdv_syncs') || !Schema::hasColumn('pdv_syncs', 'risk_flags')) {
            return [
                'available' => false,
                'window_minutes' => 1440,
                'total_syncs' => 0,
                'store_mapping_missing_count' => 0,
                'store_mapping_ambiguous_count' => 0,
                'store_mapping_by_id_fallback_count' => 0,
                'store_resolution_cnpj_rate_percent' => null,
                'user_mapping_missing_count' => 0,
                'user_login_missing_count' => 0,
                'user_mapping_by_id_fallback_count' => 0,
                'user_login_mismatch_count' => 0,
                'user_resolution_login_rate_percent' => null,
            ];
        }

        $windowMinutes = 1440;
        $windowStart = $now->subMinutes($windowMinutes);
        $baseQuery = PdvSync::query()->where('received_at', '>=', $windowStart);

        $total = (int) (clone $baseQuery)->count();

        $storeMissing = (int) (clone $baseQuery)
            ->whereJsonContains('risk_flags', 'store_mapping_missing')
            ->count();
        $storeAmbiguous = (int) (clone $baseQuery)
            ->whereJsonContains('risk_flags', 'store_mapping_ambiguous')
            ->count();
        $storeIdFallback = (int) (clone $baseQuery)
            ->whereJsonContains('risk_flags', 'store_mapping_by_id_fallback')
            ->count();

        $userMissing = (int) (clone $baseQuery)
            ->whereJsonContains('risk_flags', 'user_mapping_missing')
            ->count();
        $userLoginMissing = (int) (clone $baseQuery)
            ->whereJsonContains('risk_flags', 'user_login_missing')
            ->count();
        $userIdFallback = (int) (clone $baseQuery)
            ->whereJsonContains('risk_flags', 'user_mapping_by_id_fallback')
            ->count();
        $userLoginMismatch = (int) (clone $baseQuery)
            ->whereJsonContains('risk_flags', 'user_login_mismatch')
            ->count();

        $storeStrongCount = max(0, $total - $storeMissing - $storeAmbiguous - $storeIdFallback);
        $userStrongCount = max(0, $total - $userMissing - $userLoginMissing - $userIdFallback);

        return [
            'available' => true,
            'window_minutes' => $windowMinutes,
            'total_syncs' => $total,
            'store_mapping_missing_count' => $storeMissing,
            'store_mapping_ambiguous_count' => $storeAmbiguous,
            'store_mapping_by_id_fallback_count' => $storeIdFallback,
            'store_resolution_cnpj_rate_percent' => $total > 0 ? round(($storeStrongCount / $total) * 100, 2) : null,
            'user_mapping_missing_count' => $userMissing,
            'user_login_missing_count' => $userLoginMissing,
            'user_mapping_by_id_fallback_count' => $userIdFallback,
            'user_login_mismatch_count' => $userLoginMismatch,
            'user_resolution_login_rate_percent' => $total > 0 ? round(($userStrongCount / $total) * 100, 2) : null,
        ];
    }

    /**
     * @return array{
     *     available:bool,
     *     active_mapped_stores:int,
     *     stale_count:int,
     *     stores:array<int, array{
     *         store_pdv_id:int,
     *         store_id:int|null,
     *         alias:string|null,
     *         store_name:string|null,
     *         last_received_at:string|null,
     *         minutes_since_last_sync:int|null
     *     }>
     * }
     */
    private function getStaleStores(int $thresholdMinutes, CarbonImmutable $now): array
    {
        if (!Schema::hasTable('pdv_store_mappings') || !Schema::hasTable('pdv_syncs')) {
            return [
                'available' => false,
                'active_mapped_stores' => 0,
                'stale_count' => 0,
                'stores' => [],
            ];
        }

        $threshold = $now->subMinutes($thresholdMinutes);
        $latestByStore = DB::table('pdv_syncs')
            ->select([
                'store_pdv_id',
                'store_alias',
                DB::raw('MAX(received_at) as last_received_at'),
            ])
            ->groupBy('store_pdv_id', 'store_alias');

        $staleStoreQuery = DB::table('pdv_store_mappings as m')
            ->leftJoinSub($latestByStore, 'ls', function ($join): void {
                $join->on('ls.store_pdv_id', '=', 'm.pdv_store_id')
                    ->whereRaw('LOWER(COALESCE(ls.store_alias, \'\')) = LOWER(COALESCE(m.alias, \'\'))');
            })
            ->where('m.active', true)
            ->select([
                'm.pdv_store_id',
                'm.store_id',
                'm.alias',
                'ls.store_alias as sync_store_alias',
                'ls.last_received_at',
            ]);

        if (Schema::hasTable('stores')) {
            $staleStoreQuery
                ->leftJoin('stores as s', 's.id', '=', 'm.store_id')
                ->addSelect('s.name as store_name');
        } else {
            $staleStoreQuery->addSelect(DB::raw('NULL as store_name'));
        }

        $staleStores = $staleStoreQuery
            ->get()
            ->map(function ($row) use ($threshold, $now): ?array {
                $lastReceived = $row->last_received_at
                    ? CarbonImmutable::parse((string) $row->last_received_at)
                    : null;
                $isStale = $lastReceived === null || $lastReceived->lt($threshold);
                if (!$isStale) {
                    return null;
                }

                return [
                    'store_pdv_id' => (int) $row->pdv_store_id,
                    'store_id' => $row->store_id !== null ? (int) $row->store_id : null,
                    'alias' => $row->alias,
                    'sync_store_alias' => $row->sync_store_alias,
                    'store_name' => $row->store_name,
                    'last_received_at' => $lastReceived?->toIso8601String(),
                    'minutes_since_last_sync' => $lastReceived !== null
                        ? $lastReceived->diffInMinutes($now)
                        : null,
                ];
            })
            ->filter()
            ->values();

        $activeMappedStores = (int) DB::table('pdv_store_mappings')
            ->where('active', true)
            ->count();

        return [
            'available' => true,
            'active_mapped_stores' => $activeMappedStores,
            'stale_count' => $staleStores->count(),
            'stores' => $staleStores->all(),
        ];
    }

    private function notifyAlert(array $payload, bool $force): array
    {
        $stateKey = (string) config('pdv.monitor_state_cache_key', 'pdv:ops-monitor:state');
        $cooldownMinutes = max(1, (int) config('pdv.monitor_alert_cooldown_minutes', 30));
        $currentFingerprint = sha1((string) json_encode([
            'issues' => $payload['issues'],
            'queue_name' => data_get($payload, 'metrics.queue_name'),
            'stale_store_keys' => $this->extractStaleStoreKeys(data_get($payload, 'metrics.stale_stores', [])),
        ]));

        $state = Cache::get($stateKey);
        $state = is_array($state) ? $state : [];
        $lastFingerprint = (string) ($state['fingerprint'] ?? '');
        $lastSentAt = isset($state['last_sent_at']) ? CarbonImmutable::parse((string) $state['last_sent_at']) : null;

        if (!$force && $lastFingerprint !== '' && $lastFingerprint === $currentFingerprint && $lastSentAt !== null) {
            $minutesSinceLast = $lastSentAt->diffInMinutes(now());
            if ($minutesSinceLast < $cooldownMinutes) {
                return [
                    'sent' => false,
                    'suppressed' => true,
                    'reason' => "cooldown_active_{$minutesSinceLast}m",
                    'channels' => [],
                    'resolved' => false,
                ];
            }
        }

        $message = $this->buildAlertMessage($payload, false);
        $channels = $this->dispatchExternalNotifications($message, $payload, false);

        Cache::put($stateKey, [
            'active' => true,
            'fingerprint' => $currentFingerprint,
            'last_sent_at' => now()->toIso8601String(),
        ], now()->addDay());

        return [
            'sent' => $channels !== [],
            'suppressed' => false,
            'reason' => null,
            'channels' => $channels,
            'resolved' => false,
        ];
    }

    private function notifyRecoveryIfNeeded(array $payload, bool $force): array
    {
        $stateKey = (string) config('pdv.monitor_state_cache_key', 'pdv:ops-monitor:state');
        $state = Cache::get($stateKey);
        $state = is_array($state) ? $state : [];
        $wasActive = (bool) ($state['active'] ?? false);
        if (!$wasActive && !$force) {
            return [
                'sent' => false,
                'suppressed' => false,
                'reason' => 'no_active_alert',
                'channels' => [],
                'resolved' => false,
            ];
        }

        $message = $this->buildAlertMessage($payload, true);
        $channels = $this->dispatchExternalNotifications($message, $payload, true);
        Cache::forget($stateKey);

        return [
            'sent' => $channels !== [],
            'suppressed' => false,
            'reason' => null,
            'channels' => $channels,
            'resolved' => true,
        ];
    }

    private function buildAlertMessage(array $payload, bool $resolved): string
    {
        $prefix = $resolved ? '[PDV RECOVERY]' : '[PDV ALERT]';
        $appName = (string) data_get($payload, 'app.name', 'Laravel');
        $env = (string) data_get($payload, 'app.env', 'production');
        $queueName = (string) data_get($payload, 'metrics.queue_name', 'pdv');
        $queueBacklog = data_get($payload, 'metrics.queue_backlog');
        $queuedSyncs = (int) data_get($payload, 'metrics.queued_syncs', 0);
        $failedJobs = data_get($payload, 'metrics.failed_jobs');
        $silentStoreThreshold = (int) data_get($payload, 'metrics.silent_store_threshold_minutes', 120);
        $staleStoresAvailable = (bool) data_get($payload, 'metrics.stale_stores_available', false);
        $staleStoresCount = (int) data_get($payload, 'metrics.stale_stores_count', 0);
        $gestaoDbFailures = data_get($payload, 'metrics.gestao_db_failures_30m');
        $staleStores = data_get($payload, 'metrics.stale_stores', []);
        $staleStores = is_array($staleStores) ? $staleStores : [];
        $timestamp = (string) data_get($payload, 'timestamp', now()->toIso8601String());

        $lines = [
            "{$prefix} {$appName} ({$env})",
            "timestamp={$timestamp}",
            "queue={$queueName}",
            "queue_backlog=" . ($queueBacklog === null ? 'n/a' : (string) $queueBacklog),
            "queued_syncs={$queuedSyncs}",
            "failed_jobs=" . ($failedJobs === null ? 'n/a' : (string) $failedJobs),
            "stale_stores=" . ($staleStoresAvailable ? (string) $staleStoresCount : 'n/a'),
            "gestao_db_failures_30m=" . ($gestaoDbFailures === null ? 'n/a' : (string) $gestaoDbFailures),
            "silent_store_threshold_minutes={$silentStoreThreshold}",
        ];

        if ($staleStoresAvailable && $staleStoresCount > 0) {
            $formattedStaleStores = collect($staleStores)
                ->take(10)
                ->map(static function (array $store): string {
                    $storePdvId = (string) ($store['store_pdv_id'] ?? 'n/a');
                    $alias = (string) ($store['alias'] ?? '');
                    $minutes = isset($store['minutes_since_last_sync'])
                        ? (string) $store['minutes_since_last_sync']
                        : 'n/a';

                    $label = $alias !== '' ? "{$storePdvId}:{$alias}" : $storePdvId;

                    return "{$label}({$minutes}m)";
                })
                ->implode(', ');

            if ($formattedStaleStores !== '') {
                $lines[] = "stale_store_list={$formattedStaleStores}";
            }
        }

        if (!$resolved) {
            $issues = data_get($payload, 'issues', []);
            if (is_array($issues) && $issues !== []) {
                $lines[] = 'issues=' . implode(', ', array_map(static function (array $issue): string {
                    return sprintf(
                        '%s(value=%s threshold=%s)',
                        (string) ($issue['name'] ?? 'unknown'),
                        (string) ($issue['value'] ?? 'n/a'),
                        (string) ($issue['threshold'] ?? 'n/a')
                    );
                }, $issues));
            }
        } else {
            $lines[] = 'status=all_checks_within_threshold';
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param mixed $staleStores
     * @return array<int, int>
     */
    private function extractStaleStoreKeys(mixed $staleStores): array
    {
        if (!is_array($staleStores)) {
            return [];
        }

        return collect($staleStores)
            ->map(static function (mixed $row): string {
                $storePdvId = (int) data_get($row, 'store_pdv_id', 0);
                $alias = trim((string) data_get($row, 'alias', ''));
                if ($storePdvId <= 0) {
                    return '';
                }

                return $storePdvId . '|' . mb_strtolower($alias);
            })
            ->filter(static fn (string $key): bool => $key !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function dispatchExternalNotifications(string $message, array $payload, bool $resolved): array
    {
        $channels = [];

        $webhookUrl = trim((string) config('pdv.monitor_alert_webhook_url', ''));
        if ($webhookUrl !== '') {
            try {
                Http::timeout(10)->post($webhookUrl, [
                    'event' => $resolved ? 'pdv_monitor_recovery' : 'pdv_monitor_alert',
                    'message' => $message,
                    'payload' => $payload,
                ]);
                $channels[] = 'webhook';
            } catch (Throwable $e) {
                Log::warning('pdv.monitor.webhook_failed', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $slackWebhook = trim((string) config('pdv.monitor_alert_slack_webhook_url', ''));
        if ($slackWebhook !== '') {
            try {
                Http::timeout(10)->post($slackWebhook, [
                    'text' => $message,
                ]);
                $channels[] = 'slack';
            } catch (Throwable $e) {
                Log::warning('pdv.monitor.slack_failed', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $emails = config('pdv.monitor_alert_emails', []);
        $emails = is_array($emails) ? array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $emails
        ))) : [];

        if ($emails !== []) {
            try {
                $subjectPrefix = $resolved ? '[PDV RECOVERY]' : '[PDV ALERT]';
                $subject = sprintf(
                    '%s %s (%s)',
                    $subjectPrefix,
                    (string) config('app.name', 'Laravel'),
                    (string) config('app.env', 'production')
                );

                Mail::raw($message, static function ($mail) use ($emails, $subject): void {
                    $mail->to($emails)->subject($subject);
                });
                $channels[] = 'email';
            } catch (Throwable $e) {
                Log::warning('pdv.monitor.email_failed', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $channels;
    }
}
