<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PdvQueueConsumeCommand extends Command
{
    protected $signature = 'pdv:queue-consume
                            {--connection= : Queue connection (default: queue.default)}
                            {--queue= : Queue list (default: pdv.queue_name,default)}
                            {--max-time= : Max runtime in seconds (default: pdv.cron_queue_consumer_max_time)}
                            {--sleep= : Sleep seconds between polls (default: pdv.cron_queue_consumer_sleep)}
                            {--memory= : Memory limit in MB (default: pdv.cron_queue_consumer_memory)}
                            {--timeout= : Worker timeout in seconds (default: pdv.worker_timeout_seconds)}
                            {--tries= : Worker tries (default: pdv.job_tries)}
                            {--backoff= : Backoff list (default: pdv.job_backoff_seconds)}
                            {--once : Process one job only}
                            {--json : Output summary as JSON}';

    protected $description = 'Consume PDV queue in a cron-friendly batch (stop when empty / bounded runtime).';

    public function handle(): int
    {
        $connection = (string) ($this->option('connection') ?: config('queue.default', 'redis'));
        $queueName = trim((string) config('pdv.queue_name', 'pdv'));
        $queue = (string) ($this->option('queue') ?: ($queueName === '' ? 'default' : "{$queueName},default"));
        $maxTime = max(1, (int) ($this->option('max-time') ?: config('pdv.cron_queue_consumer_max_time', 50)));
        $sleep = max(0, (int) ($this->option('sleep') ?: config('pdv.cron_queue_consumer_sleep', 1)));
        $memory = max(64, (int) ($this->option('memory') ?: config('pdv.cron_queue_consumer_memory', 256)));
        $timeout = max(1, (int) ($this->option('timeout') ?: config('pdv.worker_timeout_seconds', 180)));
        $tries = max(1, (int) ($this->option('tries') ?: config('pdv.job_tries', 5)));
        $backoffOption = (string) ($this->option('backoff') ?: implode(',', (array) config('pdv.job_backoff_seconds', [10, 30, 60, 120])));
        $once = (bool) $this->option('once');
        $heartbeatKey = (string) config('pdv.queue_consumer_heartbeat_cache_key', 'pdv:queue-consumer:heartbeat');

        $options = [
            'connection' => $connection,
            '--queue' => $queue,
            '--sleep' => $sleep,
            '--max-time' => $maxTime,
            '--memory' => $memory,
            '--timeout' => $timeout,
            '--tries' => $tries,
            '--backoff' => $backoffOption,
        ];

        if ($once) {
            $options['--once'] = true;
        } else {
            $options['--stop-when-empty'] = true;
        }

        $startedAt = now();
        $logContext = [
            'connection' => $connection,
            'queue' => $queue,
            'max_time' => $maxTime,
            'sleep' => $sleep,
            'memory' => $memory,
            'timeout' => $timeout,
            'tries' => $tries,
            'backoff' => $backoffOption,
            'once' => $once,
        ];

        Log::channel((string) config('pdv.log_channel', 'stack'))->info('pdv.queue.consume.started', $logContext);

        $exitCode = Artisan::call('queue:work', $options);
        $output = trim(Artisan::output());

        $finishedAt = now();
        $durationMs = (int) round($startedAt->diffInMilliseconds($finishedAt));
        Cache::put($heartbeatKey, $finishedAt->toIso8601String(), $finishedAt->copy()->addMinutes(30));

        $result = [
            'exit_code' => $exitCode,
            'connection' => $connection,
            'queue' => $queue,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt->toIso8601String(),
            'duration_ms' => $durationMs,
            'heartbeat_key' => $heartbeatKey,
            'worker_output' => $output,
        ];

        Log::channel((string) config('pdv.log_channel', 'stack'))->info('pdv.queue.consume.finished', $result);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("PDV queue consume finished (exit={$exitCode}, duration={$durationMs}ms).");
            if ($output !== '') {
                $this->line($output);
            }
        }

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }
}

