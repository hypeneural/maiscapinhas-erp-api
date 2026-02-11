<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PdvSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PdvInfraCheckCommand extends Command
{
    protected $signature = 'pdv:infra-check
                            {--json : Output result as JSON}
                            {--max-queue-delay-minutes= : Override stale queue threshold}';

    protected $description = 'Validate Redis/queue/scheduler readiness for PDV webhook processing.';

    /** @var array<int, array{check:string, status:string, level:string, details:string}> */
    private array $checks = [];

    private int $errors = 0;
    private int $warnings = 0;

    public function handle(): int
    {
        $maxQueueDelayMinutes = max(
            1,
            (int) ($this->option('max-queue-delay-minutes') ?: config('pdv.queue_stale_threshold_minutes', 20))
        );
        $expectedWorkerTimeout = max(1, (int) config('pdv.worker_timeout_seconds', 180));
        $queueDefault = (string) config('queue.default', 'sync');
        $cacheDefault = (string) config('cache.default', 'file');
        $redisQueueConnection = (string) config('queue.connections.redis.connection', 'default');
        $redisCacheConnection = (string) config('cache.stores.redis.connection', 'cache');
        $redisRetryAfter = (int) config('queue.connections.redis.retry_after', 90);
        $redisBlockFor = config('queue.connections.redis.block_for');
        $pdvQueue = (string) config('pdv.queue_name', 'pdv');

        $this->addCheck(
            'Queue connection',
            $queueDefault === 'redis',
            "queue.default={$queueDefault}. Expected redis for production ingestion."
        );

        $this->addCheck(
            'Cache store',
            $cacheDefault === 'redis',
            "cache.default={$cacheDefault}. Redis is recommended for lock/metrics consistency.",
            'warning'
        );

        $this->addCheck(
            'PDV queue name',
            trim($pdvQueue) !== '',
            "pdv.queue_name={$pdvQueue}."
        );

        $this->addCheck(
            'Redis retry_after vs worker timeout',
            $redisRetryAfter > $expectedWorkerTimeout,
            "retry_after={$redisRetryAfter}s, expected_worker_timeout={$expectedWorkerTimeout}s.",
            'warning'
        );

        $this->addCheck(
            'Redis block_for',
            $redisBlockFor !== null,
            'queue.connections.redis.block_for is null. A small value (e.g. 5) reduces polling overhead.',
            'warning'
        );

        [$queuePingOk, $queuePingDetails] = $this->pingRedisConnection($redisQueueConnection);
        $this->addCheck(
            "Redis ping ({$redisQueueConnection})",
            $queuePingOk,
            $queuePingDetails
        );

        [$cachePingOk, $cachePingDetails] = $this->pingRedisConnection($redisCacheConnection);
        $this->addCheck(
            "Redis ping ({$redisCacheConnection})",
            $cachePingOk,
            $cachePingDetails
        );

        $this->checkSchedulerHeartbeat();
        $this->checkPdvBacklog($maxQueueDelayMinutes);
        $this->checkPdvFailedSyncs();
        $this->checkFailedJobsTable();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $this->errors === 0,
                'errors' => $this->errors,
                'warnings' => $this->warnings,
                'checks' => $this->checks,
                'timestamp' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->errors === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['Check', 'Status', 'Details'],
            collect($this->checks)->map(function (array $item): array {
                return [
                    $item['check'],
                    strtoupper($item['status']),
                    $item['details'],
                ];
            })->all()
        );

        $this->newLine();
        $this->line(sprintf(
            'PDV infra check summary: errors=%d warnings=%d',
            $this->errors,
            $this->warnings
        ));

        if ($this->errors > 0) {
            $this->error('Infrastructure check failed.');
            return self::FAILURE;
        }

        $this->info('Infrastructure check passed.');
        return self::SUCCESS;
    }

    /**
     * @return array{0:bool,1:string}
     */
    private function pingRedisConnection(string $connection): array
    {
        try {
            $response = Redis::connection($connection)->ping();
            $isOk = $response === true
                || $response === 1
                || str_contains(strtoupper((string) $response), 'PONG');

            return [
                $isOk,
                $isOk
                    ? "Connection {$connection} reachable (ping={$response})."
                    : "Connection {$connection} ping returned unexpected value ({$response}).",
            ];
        } catch (Throwable $e) {
            return [false, "Connection {$connection} failed: " . $e->getMessage()];
        }
    }

    private function checkSchedulerHeartbeat(): void
    {
        $raw = Cache::get('pdv:scheduler:heartbeat');
        if (!is_string($raw) || trim($raw) === '') {
            $this->addCheck(
                'Scheduler heartbeat',
                false,
                'No heartbeat found in cache key pdv:scheduler:heartbeat. schedule:run may not be active.',
                'warning'
            );
            return;
        }

        try {
            $heartbeatAt = CarbonImmutable::parse($raw);
        } catch (Throwable $e) {
            $this->addCheck(
                'Scheduler heartbeat',
                false,
                'Invalid heartbeat value in cache: ' . $raw,
                'warning'
            );
            return;
        }

        $ageSeconds = $heartbeatAt->diffInSeconds(now());
        $this->addCheck(
            'Scheduler heartbeat',
            $ageSeconds <= 180,
            "last={$heartbeatAt->toIso8601String()} age={$ageSeconds}s.",
            'warning'
        );
    }

    private function checkPdvBacklog(int $maxQueueDelayMinutes): void
    {
        try {
            $cutoff = now()->subMinutes($maxQueueDelayMinutes);
            $queuedStale = PdvSync::query()
                ->where('status', PdvSync::STATUS_QUEUED)
                ->where('received_at', '<=', $cutoff)
                ->count();

            $this->addCheck(
                "Queued syncs older than {$maxQueueDelayMinutes}m",
                $queuedStale === 0,
                "queued_stale={$queuedStale}.",
                'warning'
            );
        } catch (Throwable $e) {
            $this->addCheck(
                'Queued syncs stale check',
                false,
                'Could not query pdv_syncs table: ' . $e->getMessage(),
                'warning'
            );
        }
    }

    private function checkPdvFailedSyncs(): void
    {
        try {
            $failed = PdvSync::query()
                ->where('status', PdvSync::STATUS_FAILED)
                ->count();

            $this->addCheck(
                'Failed PDV syncs',
                $failed === 0,
                "failed_syncs={$failed}.",
                'warning'
            );
        } catch (Throwable $e) {
            $this->addCheck(
                'Failed PDV syncs',
                false,
                'Could not query failed PDV syncs: ' . $e->getMessage(),
                'warning'
            );
        }
    }

    private function checkFailedJobsTable(): void
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');
        if ($table === '' || !Schema::hasTable($table)) {
            $this->addCheck(
                'failed_jobs table',
                false,
                "Table {$table} not found. Failed queue visibility may be limited.",
                'warning'
            );
            return;
        }

        try {
            $count = DB::table($table)->count();
            $this->addCheck(
                'failed_jobs backlog',
                $count === 0,
                "table={$table} count={$count}.",
                'warning'
            );
        } catch (Throwable $e) {
            $this->addCheck(
                'failed_jobs backlog',
                false,
                'Could not query failed_jobs table: ' . $e->getMessage(),
                'warning'
            );
        }
    }

    private function addCheck(string $check, bool $ok, string $details, string $level = 'error'): void
    {
        $status = $ok ? 'ok' : ($level === 'warning' ? 'warn' : 'fail');
        if (!$ok) {
            if ($level === 'warning') {
                $this->warnings++;
            } else {
                $this->errors++;
            }
        }

        $this->checks[] = [
            'check' => $check,
            'status' => $status,
            'level' => $level,
            'details' => $details,
        ];
    }
}
