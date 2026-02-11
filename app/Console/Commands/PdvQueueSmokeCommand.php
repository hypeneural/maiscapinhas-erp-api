<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PdvQueueSmokeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PdvQueueSmokeCommand extends Command
{
    protected $signature = 'pdv:queue-smoke
                            {--wait=20 : Seconds to wait for worker consumption}
                            {--queue= : Queue name override}';

    protected $description = 'Dispatch a PDV smoke job and verify if worker consumes it.';

    public function handle(): int
    {
        $waitSeconds = max(0, (int) $this->option('wait'));
        $queue = (string) ($this->option('queue') ?: config('pdv.queue_name', 'pdv'));
        $token = (string) Str::uuid();
        $cacheKey = 'pdv:queue-smoke:' . $token;

        Cache::forget($cacheKey);

        PdvQueueSmokeJob::dispatch($cacheKey)->onQueue($queue);

        $this->info("Smoke job dispatched. queue={$queue} token={$token}");

        if ($waitSeconds <= 0) {
            $this->line('Wait disabled. Use --wait to block and confirm consumption.');
            return self::SUCCESS;
        }

        $deadline = microtime(true) + $waitSeconds;
        while (microtime(true) < $deadline) {
            $processedAt = Cache::get($cacheKey);
            if (is_string($processedAt) && trim($processedAt) !== '') {
                $this->info("Smoke job consumed successfully at {$processedAt}.");
                return self::SUCCESS;
            }

            usleep(250000);
        }

        $this->warn(sprintf(
            'Smoke job not consumed within %d seconds. Check if worker is active for queue [%s].',
            $waitSeconds,
            $queue
        ));

        return self::FAILURE;
    }
}
