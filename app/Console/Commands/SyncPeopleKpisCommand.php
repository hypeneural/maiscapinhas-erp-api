<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Analytics\Services\PeopleAnalyticsSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncPeopleKpisCommand extends Command
{
    protected $signature = 'people:sync-kpis 
                            {--store= : Store ID to sync}
                            {--date= : Date to sync (YYYY-MM-DD)}
                            {--all-stores : Sync all active stores}';

    protected $description = 'Sync People Analytics KPIs from FastAPI';

    public function __construct(
        private PeopleAnalyticsSyncService $syncService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::today();

        $this->info("Syncing KPIs for date: {$date->format('Y-m-d')}");

        if ($this->option('all-stores')) {
            return $this->syncAllStores($date);
        }

        $storeId = $this->option('store');

        if (!$storeId) {
            $this->error('Please provide --store=ID or --all-stores');
            return self::FAILURE;
        }

        return $this->syncStore((int) $storeId, $date);
    }

    private function syncStore(int $storeId, Carbon $date): int
    {
        $this->info("Syncing store ID: {$storeId}");

        try {
            $results = $this->syncService->syncKpis($storeId, $date);

            if (empty($results)) {
                $this->warn('No KPIs synced (API may be unavailable or no data)');
                return self::SUCCESS;
            }

            $this->info("Synced " . count($results) . " shift(s)");

            foreach ($results as $kpi) {
                $this->line("  - Shift {$kpi->shift_code}: in={$kpi->in_count}, out={$kpi->out_count}");
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to sync: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function syncAllStores(Carbon $date): int
    {
        $stores = \App\Models\Store::active()->get();

        $this->info("Syncing " . $stores->count() . " store(s)");

        $success = 0;
        $failed = 0;

        foreach ($stores as $store) {
            try {
                $results = $this->syncService->syncKpis($store->id, $date);
                $this->line("  ✓ {$store->name}: " . count($results) . " shift(s)");
                $success++;
            } catch (\Exception $e) {
                $this->error("  ✗ {$store->name}: " . $e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Complete: {$success} succeeded, {$failed} failed");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
