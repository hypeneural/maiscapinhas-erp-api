<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PdvPurgeRawPayloadsCommand extends Command
{
    protected $signature = 'pdv:purge-raw-payloads
                            {--days= : Override retention days}
                            {--chunk=1000 : Delete chunk size}
                            {--dry-run : Only show rows that would be deleted}';

    protected $description = 'Purge old PDV RAW payloads from pdv_sync_payloads while keeping pdv_syncs metadata.';

    public function handle(): int
    {
        $retentionDays = (int) ($this->option('days') ?: config('pdv.raw_retention_days', 30));
        $chunkSize = max(100, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = CarbonImmutable::now()->subDays($retentionDays);

        $baseQuery = DB::table('pdv_sync_payloads')
            ->where('created_at', '<', $cutoff);

        $total = (int) $baseQuery->count();

        $this->info(sprintf(
            'PDV RAW purge cutoff=%s retention_days=%d candidates=%d dry_run=%s',
            $cutoff->toDateTimeString(),
            $retentionDays,
            $total,
            $dryRun ? 'true' : 'false'
        ));

        if ($dryRun || $total === 0) {
            return self::SUCCESS;
        }

        $deleted = 0;
        do {
            $ids = DB::table('pdv_sync_payloads')
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $batchDeleted = DB::table('pdv_sync_payloads')->whereIn('id', $ids)->delete();
            $deleted += (int) $batchDeleted;

            $this->line("Deleted batch: {$batchDeleted} (total={$deleted})");
        } while (count($ids) === $chunkSize);

        $this->info("PDV RAW purge completed. Deleted={$deleted}");

        return self::SUCCESS;
    }
}
