<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tv\TvHealthService;
use Illuminate\Console\Command;

class MonitorTvHealthCommand extends Command
{
    protected $signature = 'tv:monitor-health
        {--no-webhooks : Apenas reconcilia o banco sem disparar webhooks de saída}
        {--json : Emite o resultado em JSON}';

    protected $description = 'Detecta TVs offline/degradadas, fecha sessões expiradas em last_ping_at e dispara webhooks de transição.';

    public function handle(TvHealthService $healthService): int
    {
        $dispatchWebhooks = !$this->option('no-webhooks');
        $stats = $healthService->reconcileStaleScreens(null, $dispatchWebhooks);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'timestamp' => now()->toIso8601String(),
                'stats' => $stats,
            ], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '[tv:monitor-health] checked=%d offline=%d degraded=%d sessions_closed=%d webhooks=%d',
            $stats['checked'],
            $stats['transitioned_offline'],
            $stats['transitioned_degraded'],
            $stats['sessions_closed'],
            $stats['webhooks_queued']
        ));

        return self::SUCCESS;
    }
}
