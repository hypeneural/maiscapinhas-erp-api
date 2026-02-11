<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessPdvSyncJob;
use App\Models\PdvSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PdvRetryFailedSyncsCommand extends Command
{
    protected $signature = 'pdv:retry-failed
                            {--limit= : Max syncs to requeue}
                            {--max-attempts= : Max attempts allowed}
                            {--older-than-minutes= : Retry only failed rows older than X minutes}
                            {--store-pdv-id= : Filter one PDV store}
                            {--dry-run : Show candidates without requeue}';

    protected $description = 'Requeue failed PDV syncs using a controlled retry policy.';

    public function handle(): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: config('pdv.retry_failed_limit', 200)));
        $maxAttempts = max(1, (int) ($this->option('max-attempts') ?: config('pdv.retry_failed_max_attempts', 8)));
        $olderThanMinutes = max(1, (int) ($this->option('older-than-minutes') ?: config('pdv.retry_failed_older_than_minutes', 15)));
        $storePdvId = $this->option('store-pdv-id');
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = CarbonImmutable::now()->subMinutes($olderThanMinutes);

        $query = PdvSync::query()
            ->where('status', PdvSync::STATUS_FAILED)
            ->where('attempts', '<', $maxAttempts)
            ->where(function ($q) use ($cutoff) {
                $q->where('updated_at', '<=', $cutoff)
                    ->orWhereNull('updated_at');
            })
            ->orderBy('updated_at')
            ->orderBy('id');

        if ($storePdvId !== null && $storePdvId !== '') {
            $query->where('store_pdv_id', (int) $storePdvId);
        }

        $candidates = $query->limit($limit)->get(['id', 'sync_id', 'store_pdv_id', 'attempts', 'updated_at']);

        $this->info(sprintf(
            'PDV retry failed candidates=%d limit=%d max_attempts=%d older_than_minutes=%d dry_run=%s',
            $candidates->count(),
            $limit,
            $maxAttempts,
            $olderThanMinutes,
            $dryRun ? 'true' : 'false'
        ));

        if ($dryRun || $candidates->isEmpty()) {
            foreach ($candidates as $candidate) {
                $this->line(sprintf(
                    '- id=%d sync_id=%s store_pdv_id=%d attempts=%d',
                    $candidate->id,
                    $candidate->sync_id,
                    $candidate->store_pdv_id,
                    $candidate->attempts
                ));
            }

            return self::SUCCESS;
        }

        $requeued = 0;
        foreach ($candidates as $candidate) {
            DB::transaction(function () use ($candidate, &$requeued) {
                $updated = DB::table('pdv_syncs')
                    ->where('id', $candidate->id)
                    ->where('status', PdvSync::STATUS_FAILED)
                    ->update([
                        'status' => PdvSync::STATUS_QUEUED,
                        'queued_at' => now(),
                        'updated_at' => now(),
                    ]);

                if ($updated === 1) {
                    ProcessPdvSyncJob::dispatch($candidate->id)
                        ->onQueue((string) config('pdv.queue_name', 'pdv'));
                    $requeued++;
                }
            });
        }

        $this->info("PDV failed retry completed. Requeued={$requeued}");

        return self::SUCCESS;
    }
}
