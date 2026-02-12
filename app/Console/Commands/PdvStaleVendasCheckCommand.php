<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PdvStaleVendasCheckCommand extends Command
{
    protected $signature = 'pdv:stale-vendas-check
                            {--hours= : Threshold hours without snapshot visibility}
                            {--recent-days= : Only evaluate vendas created in this recency window}
                            {--limit= : Max sample rows returned in output} 
                            {--json : Output as JSON}';

    protected $description = 'Detect PDV vendas that were not seen in snapshot for too long (possible cancellation signal).';

    public function handle(): int
    {
        $enabled = (bool) config('pdv.stale_vendas_check_enabled', false);
        $thresholdHours = max(
            1,
            (int) ($this->option('hours') ?: config('pdv.stale_vendas_threshold_hours', 72))
        );
        $recentDays = max(
            1,
            (int) ($this->option('recent-days') ?: config('pdv.stale_vendas_recent_window_days', 7))
        );
        $limit = max(
            1,
            (int) ($this->option('limit') ?: config('pdv.stale_vendas_limit', 200))
        );

        if (!$enabled) {
            $payload = [
                'status' => 'disabled',
                'enabled' => false,
                'stale_count' => 0,
                'threshold_hours' => $thresholdHours,
                'recent_days' => $recentDays,
                'timestamp' => now()->toIso8601String(),
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->warn('PDV stale vendas check is disabled by configuration.');
            }

            return self::SUCCESS;
        }

        if (!Schema::hasTable('pdv_vendas')
            || !Schema::hasColumn('pdv_vendas', 'last_seen_in_snapshot_at')
        ) {
            $payload = [
                'status' => 'unavailable',
                'enabled' => true,
                'reason' => 'pdv_vendas.last_seen_in_snapshot_at not available',
                'stale_count' => 0,
                'timestamp' => now()->toIso8601String(),
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('pdv_vendas.last_seen_in_snapshot_at is not available.');
            }

            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $staleThreshold = $now->subHours($thresholdHours);
        $recentWindowStart = $now->subDays($recentDays);

        $query = DB::table('pdv_vendas')
            ->where('created_at', '>=', $recentWindowStart->toDateTimeString())
            ->where(function ($builder) use ($staleThreshold): void {
                $builder->whereNull('last_seen_in_snapshot_at')
                    ->orWhere('last_seen_in_snapshot_at', '<', $staleThreshold->toDateTimeString());
            });

        $staleCount = (int) (clone $query)->count();
        $sample = (clone $query)
            ->orderBy('created_at')
            ->limit($limit)
            ->get([
                'store_pdv_id',
                'canal',
                'id_operacao',
                'id_turno',
                'data_hora',
                'created_at',
                'last_seen_in_snapshot_at',
            ])
            ->map(static function (object $row) use ($now): array {
                $lastSeen = $row->last_seen_in_snapshot_at !== null
                    ? CarbonImmutable::parse((string) $row->last_seen_in_snapshot_at)
                    : null;

                return [
                    'store_pdv_id' => (int) $row->store_pdv_id,
                    'canal' => (string) ($row->canal ?? 'HIPER_CAIXA'),
                    'id_operacao' => (int) $row->id_operacao,
                    'id_turno' => $row->id_turno !== null ? (string) $row->id_turno : null,
                    'data_hora' => $row->data_hora !== null ? (string) $row->data_hora : null,
                    'created_at' => $row->created_at !== null ? (string) $row->created_at : null,
                    'last_seen_in_snapshot_at' => $lastSeen?->toIso8601String(),
                    'hours_since_last_seen' => $lastSeen !== null ? $lastSeen->diffInHours($now) : null,
                ];
            })
            ->values()
            ->all();

        $payload = [
            'status' => $staleCount > 0 ? 'alert' : 'ok',
            'enabled' => true,
            'stale_count' => $staleCount,
            'threshold_hours' => $thresholdHours,
            'recent_days' => $recentDays,
            'sample_limit' => $limit,
            'sample' => $sample,
            'timestamp' => $now->toIso8601String(),
        ];

        if ($staleCount > 0) {
            Log::warning('pdv.stale_vendas.detected', [
                'stale_count' => $staleCount,
                'threshold_hours' => $thresholdHours,
                'recent_days' => $recentDays,
                'sample' => $sample,
            ]);
        } else {
            Log::info('pdv.stale_vendas.ok', [
                'threshold_hours' => $thresholdHours,
                'recent_days' => $recentDays,
            ]);
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf(
                '[PDV stale vendas] status=%s stale_count=%d threshold_hours=%d recent_days=%d',
                strtoupper((string) $payload['status']),
                $staleCount,
                $thresholdHours,
                $recentDays
            ));
        }

        return $staleCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}

