<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TvScreen;
use App\Models\TvScreenSession;
use Illuminate\Console\Command;

class RepairLegacyTvSessionsCommand extends Command
{
    protected $signature = 'tv:repair-legacy-sessions
        {--apply : Aplica as correções no banco (sem esta flag roda em dry-run)}';

    protected $description = 'Fecha sessões abertas zumbis e corrige duration_minutes inflado pelo bug antigo de close() usando last_ping_at.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $offlineThreshold = (int) config('tv.offline_threshold_seconds', 180);
        $cutoff = now()->subSeconds($offlineThreshold);

        $zombieSessions = TvScreenSession::query()
            ->whereNull('ended_at')
            ->where('last_ping_at', '<', $cutoff)
            ->get();

        $inflatedSessions = TvScreenSession::query()
            ->whereNotNull('ended_at')
            ->whereNotNull('last_ping_at')
            ->whereColumn('ended_at', '>', 'last_ping_at')
            ->where('ended_reason', 'timeout_disconnected')
            ->get();

        $staleOnlineScreens = TvScreen::query()
            ->where('is_online', true)
            ->whereNotNull('last_ping_at')
            ->where('last_ping_at', '<', $cutoff)
            ->get();

        $this->info(sprintf(
            '[%s] zombie_open_sessions=%d inflated_closed_sessions=%d stale_online_screens=%d',
            $apply ? 'APPLY' : 'DRY-RUN',
            $zombieSessions->count(),
            $inflatedSessions->count(),
            $staleOnlineScreens->count()
        ));

        if (!$apply) {
            $this->comment('Nenhuma alteração aplicada. Execute com --apply para reparar o histórico.');

            return self::SUCCESS;
        }

        foreach ($zombieSessions as $session) {
            $session->close('timeout_disconnected', $session->last_ping_at ?? $session->started_at);
        }

        foreach ($inflatedSessions as $session) {
            $realEnd = $session->last_ping_at;
            if ($realEnd && $session->started_at) {
                $realDuration = max(0, (int) $session->started_at->diffInMinutes($realEnd));
                $session->update([
                    'ended_at' => $realEnd,
                    'duration_minutes' => $realDuration,
                ]);
            }
        }

        foreach ($staleOnlineScreens as $screen) {
            $screen->update([
                'is_online' => false,
                'health_status' => 'offline',
                'offline_since' => $screen->last_ping_at,
                'status_changed_at' => $screen->last_ping_at,
                'online_since' => null,
            ]);
        }

        $this->info('Reparo concluído com sucesso.');

        return self::SUCCESS;
    }
}
