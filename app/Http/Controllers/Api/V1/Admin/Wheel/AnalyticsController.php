<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Wheel;

use App\Http\Controllers\Controller;
use App\Models\WheelScreen;
use App\Models\WheelCampaign;
use App\Models\WheelEvent;
use App\Enums\CampaignStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Wheel - Analytics
 *
 * API para analytics e dashboard do módulo Roleta.
 */
class AnalyticsController extends Controller
{
    /**
     * Resumo geral do dashboard.
     */
    public function summary(Request $request): JsonResponse
    {
        $screensOnline = WheelScreen::online()->count();
        $screensTotal = WheelScreen::count();
        $screensOffline = $screensTotal - $screensOnline;

        $activeCampaigns = WheelCampaign::active()->count();
        $draftCampaigns = WheelCampaign::draft()->count();

        // Eventos das últimas 24h
        $recentEvents = WheelEvent::recent(24)->count();

        // Prêmios ganhos hoje (eventos do tipo prize_won)
        $prizesWonToday = WheelEvent::where('type', WheelEvent::TYPE_PRIZE_WON)
            ->whereDate('created_at', today())
            ->count();

        // Giros hoje
        $spinsToday = WheelEvent::where('type', WheelEvent::TYPE_SPIN_COMPLETED)
            ->whereDate('created_at', today())
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'screens' => [
                    'total' => $screensTotal,
                    'online' => $screensOnline,
                    'offline' => $screensOffline,
                ],
                'campaigns' => [
                    'active' => $activeCampaigns,
                    'draft' => $draftCampaigns,
                ],
                'today' => [
                    'spins' => $spinsToday,
                    'prizes_won' => $prizesWonToday,
                ],
                'events_24h' => $recentEvents,
            ],
        ]);
    }

    /**
     * Contagem de TVs online.
     */
    public function screensOnline(): JsonResponse
    {
        $online = WheelScreen::online()->count();
        $total = WheelScreen::count();

        return response()->json([
            'success' => true,
            'data' => [
                'value' => $online,
                'total' => $total,
                'label' => "{$online}/{$total}",
            ],
        ]);
    }

    /**
     * Contagem de campanhas ativas.
     */
    public function activeCampaigns(): JsonResponse
    {
        $count = WheelCampaign::active()->count();

        return response()->json([
            'success' => true,
            'data' => [
                'value' => $count,
                'label' => (string) $count,
            ],
        ]);
    }

    /**
     * Giros realizados hoje.
     */
    public function spinsToday(): JsonResponse
    {
        $count = WheelEvent::where('type', WheelEvent::TYPE_SPIN_COMPLETED)
            ->whereDate('created_at', today())
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'value' => $count,
                'label' => (string) $count,
            ],
        ]);
    }

    /**
     * Prêmios ganhos hoje.
     */
    public function prizesWon(Request $request): JsonResponse
    {
        $query = WheelEvent::where('type', WheelEvent::TYPE_PRIZE_WON);

        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->date);
        } else {
            $query->whereDate('created_at', today());
        }

        $count = $query->count();

        // Breakdown por tipo de prêmio
        $breakdown = $query->get()
            ->groupBy(fn($e) => $e->payload['prize_type'] ?? 'unknown')
            ->map(fn($group) => $group->count());

        return response()->json([
            'success' => true,
            'data' => [
                'value' => $count,
                'label' => (string) $count,
                'breakdown' => $breakdown,
            ],
        ]);
    }

    /**
     * Analytics detalhado com métricas completas.
     */
    public function detailed(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|in:today,week,month,custom',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'campaign_key' => 'nullable|string',
            'store_id' => 'nullable|integer',
        ]);

        // Determinar período
        $period = $request->input('period', 'today');
        $from = match ($period) {
            'today' => today(),
            'week' => today()->subDays(7),
            'month' => today()->subDays(30),
            'custom' => $request->input('from', today()->subDays(7)),
            default => today(),
        };
        $to = $period === 'custom'
            ? $request->input('to', today())
            : today();

        // Base query para eventos
        $baseQuery = WheelEvent::whereBetween('created_at', [$from, $to->endOfDay()]);

        // Filtros opcionais
        if ($request->filled('campaign_key')) {
            $campaign = WheelCampaign::where('campaign_key', $request->campaign_key)->first();
            if ($campaign) {
                $baseQuery->where('campaign_id', $campaign->id);
            }
        }

        if ($request->filled('store_id')) {
            $screenIds = WheelScreen::where('store_id', $request->store_id)->pluck('id');
            $baseQuery->whereIn('screen_id', $screenIds);
        }

        // Total de giros
        $spins = (clone $baseQuery)->where('type', WheelEvent::TYPE_SPIN_COMPLETED)->count();

        // Prêmios ganhos
        $prizesWon = (clone $baseQuery)->where('type', WheelEvent::TYPE_PRIZE_WON)->count();

        // Telefones únicos (estimado via eventos de player_joined)
        $uniquePhones = (clone $baseQuery)
            ->where('type', 'player_joined')
            ->distinct()
            ->count('payload->phone_hash');

        // Conversion rate
        $conversionRate = $spins > 0 ? round(($prizesWon / $spins) * 100, 1) : 0;

        // By day
        $byDay = (clone $baseQuery)
            ->where('type', WheelEvent::TYPE_SPIN_COMPLETED)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as spins')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($row) => [
                'date' => $row->date,
                'spins' => $row->spins,
            ]);

        // By campaign
        $byCampaign = (clone $baseQuery)
            ->where('type', WheelEvent::TYPE_SPIN_COMPLETED)
            ->whereNotNull('campaign_id')
            ->selectRaw('campaign_id, COUNT(*) as spins')
            ->groupBy('campaign_id')
            ->get()
            ->map(function ($row) {
                $campaign = WheelCampaign::find($row->campaign_id);
                return [
                    'campaign_key' => $campaign?->campaign_key,
                    'name' => $campaign?->name,
                    'spins' => $row->spins,
                ];
            })->filter(fn($r) => $r['campaign_key'] !== null);

        // By prize
        $byPrize = (clone $baseQuery)
            ->where('type', WheelEvent::TYPE_PRIZE_WON)
            ->get()
            ->groupBy(fn($e) => $e->payload['prize_key'] ?? 'unknown')
            ->map(fn($group, $key) => [
                'prize_key' => $key,
                'name' => $group->first()->payload['prize_name'] ?? $key,
                'count' => $group->count(),
                'percentage' => $prizesWon > 0 ? round(($group->count() / $prizesWon) * 100, 1) : 0,
            ])
            ->values();

        // Inventory alerts
        $inventoryAlerts = \App\Models\WheelInventory::with(['campaign', 'prize'])
            ->whereRaw('remaining <= total_limit * 0.2') // Less than 20%
            ->get()
            ->map(fn($inv) => [
                'campaign_key' => $inv->campaign->campaign_key,
                'prize_key' => $inv->prize->prize_key,
                'prize_name' => $inv->prize->name,
                'remaining' => $inv->remaining,
                'total' => $inv->total_limit,
                'alert_level' => $inv->remaining <= ($inv->total_limit * 0.05) ? 'critical' : 'low',
            ]);

        // Screens needing attention (offline > 1h)
        $screensNeedingAttention = WheelScreen::offline(60) // 60 min
            ->where('status', 'active')
            ->with('store')
            ->get()
            ->map(fn($s) => [
                'screen_key' => $s->screen_key,
                'name' => $s->name,
                'store' => $s->store?->name,
                'issue' => $s->last_seen_at
                    ? ($s->last_seen_at->lt(now()->subHours(24)) ? 'offline_24h' : 'offline_1h')
                    : 'never_connected',
                'last_seen' => $s->last_seen_at?->toISOString(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
                'totals' => [
                    'spins' => $spins,
                    'prizes_won' => $prizesWon,
                    'unique_phones' => $uniquePhones,
                    'conversion_rate' => $conversionRate,
                ],
                'by_day' => $byDay,
                'by_campaign' => $byCampaign->values(),
                'by_prize' => $byPrize,
                'inventory_alerts' => $inventoryAlerts,
                'screens_needing_attention' => $screensNeedingAttention,
            ],
        ]);
    }

    /**
     * Performance por loja/screen.
     * 
     * Métricas agrupadas por store incluindo spins, prêmios e taxa de conversão.
     */
    public function performanceByStore(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|in:today,week,month',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $period = $request->input('period', 'week');
        $from = match ($period) {
            'today' => today(),
            'week' => today()->subDays(7),
            'month' => today()->subDays(30),
            default => $request->input('from', today()->subDays(7)),
        };
        $to = today();

        $screens = WheelScreen::with('store')->get();

        $data = $screens->map(function ($screen) use ($from, $to) {
            $baseQuery = WheelEvent::where('screen_id', $screen->id)
                ->whereBetween('created_at', [$from, $to->endOfDay()]);

            $spins = (clone $baseQuery)->where('type', WheelEvent::TYPE_SPIN_COMPLETED)->count();
            $prizesWon = (clone $baseQuery)->where('type', WheelEvent::TYPE_PRIZE_WON)->count();
            $playersJoined = (clone $baseQuery)->where('type', WheelEvent::TYPE_PLAYER_JOINED)->count();

            // Calcular resgates via WheelSpin
            $redeemed = \App\Models\WheelSpin::where('screen_id', $screen->id)
                ->whereBetween('created_at', [$from, $to->endOfDay()])
                ->where('redeemed', true)
                ->count();

            return [
                'screen_key' => $screen->screen_key,
                'screen_name' => $screen->name,
                'store_id' => $screen->store_id,
                'store_name' => $screen->store?->name ?? 'N/A',
                'metrics' => [
                    'spins' => $spins,
                    'prizes_won' => $prizesWon,
                    'players_joined' => $playersJoined,
                    'redeemed' => $redeemed,
                    'conversion_rate' => $spins > 0 ? round(($prizesWon / $spins) * 100, 1) : 0,
                    'redemption_rate' => $prizesWon > 0 ? round(($redeemed / $prizesWon) * 100, 1) : 0,
                ],
            ];
        })->filter(fn($s) => $s['metrics']['spins'] > 0);

        // Agrupar por store
        $byStore = $data->groupBy('store_id')->map(function ($screens, $storeId) {
            $first = $screens->first();
            return [
                'store_id' => $storeId,
                'store_name' => $first['store_name'],
                'screens_count' => $screens->count(),
                'totals' => [
                    'spins' => $screens->sum('metrics.spins'),
                    'prizes_won' => $screens->sum('metrics.prizes_won'),
                    'players_joined' => $screens->sum('metrics.players_joined'),
                    'redeemed' => $screens->sum('metrics.redeemed'),
                ],
                'screens' => $screens->values(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'by_store' => $byStore,
            ],
        ]);
    }

    /**
     * Pico de horário.
     * 
     * Distribuição de spins por hora do dia e dia da semana.
     */
    public function peakHours(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|in:today,week,month',
        ]);

        $period = $request->input('period', 'week');
        $from = match ($period) {
            'today' => today(),
            'week' => today()->subDays(7),
            'month' => today()->subDays(30),
            default => today()->subDays(7),
        };

        $events = WheelEvent::where('type', WheelEvent::TYPE_SPIN_COMPLETED)
            ->where('created_at', '>=', $from)
            ->get();

        // Por hora do dia (0-23)
        $byHour = collect(range(0, 23))->mapWithKeys(fn($h) => [$h => 0])->toArray();
        foreach ($events as $event) {
            $hour = $event->created_at->hour;
            $byHour[$hour]++;
        }

        // Por dia da semana (0=domingo, 6=sábado)
        $dayNames = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
        $byDayOfWeek = collect(range(0, 6))->mapWithKeys(fn($d) => [$d => ['name' => $dayNames[$d], 'spins' => 0]])->toArray();
        foreach ($events as $event) {
            $dow = $event->created_at->dayOfWeek;
            $byDayOfWeek[$dow]['spins']++;
        }

        // Encontrar horário de pico
        $peakHour = array_search(max($byHour), $byHour);
        $peakDay = collect($byDayOfWeek)->sortByDesc('spins')->keys()->first();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'total_spins' => $events->count(),
                'peak_hour' => [
                    'hour' => $peakHour,
                    'label' => sprintf('%02d:00 - %02d:59', $peakHour, $peakHour),
                    'spins' => $byHour[$peakHour],
                ],
                'peak_day' => [
                    'day' => $peakDay,
                    'name' => $dayNames[$peakDay],
                    'spins' => $byDayOfWeek[$peakDay]['spins'],
                ],
                'by_hour' => collect($byHour)->map(fn($spins, $hour) => [
                    'hour' => $hour,
                    'label' => sprintf('%02d:00', $hour),
                    'spins' => $spins,
                ])->values(),
                'by_day_of_week' => collect($byDayOfWeek)->values(),
            ],
        ]);
    }

    /**
     * Mapa de calor geográfico.
     * 
     * Participação por cidade e estado dos jogadores.
     */
    public function geographicHeatmap(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|in:today,week,month',
        ]);

        $period = $request->input('period', 'week');
        $from = match ($period) {
            'today' => today(),
            'week' => today()->subDays(7),
            'month' => today()->subDays(30),
            default => today()->subDays(7),
        };

        // Buscar players que jogaram no período via session_players
        $playerIds = \App\Models\WheelSessionPlayer::where('created_at', '>=', $from)
            ->distinct()
            ->pluck('player_id');

        $players = \App\Models\WheelPlayer::whereIn('id', $playerIds)
            ->whereNotNull('state')
            ->get();

        // Por estado
        $byState = $players->groupBy('state')
            ->map(fn($group, $state) => [
                'state' => $state,
                'players' => $group->count(),
            ])
            ->sortByDesc('players')
            ->values();

        // Por cidade
        $byCity = $players->filter(fn($p) => $p->city)
            ->groupBy(fn($p) => ($p->city ?? 'Desconhecida') . ', ' . ($p->state ?? ''))
            ->map(fn($group, $cityState) => [
                'city_state' => $cityState,
                'players' => $group->count(),
            ])
            ->sortByDesc('players')
            ->take(20)
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'total_players' => $players->count(),
                'coverage' => [
                    'states' => $byState->count(),
                    'cities' => $byCity->count(),
                ],
                'by_state' => $byState,
                'by_city' => $byCity,
            ],
        ]);
    }

    /**
     * Métricas de ROI.
     * 
     * Valor médio por jogador e custo por engajamento.
     */
    public function roiMetrics(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|in:today,week,month',
            'campaign_key' => 'nullable|string',
        ]);

        $period = $request->input('period', 'week');
        $from = match ($period) {
            'today' => today(),
            'week' => today()->subDays(7),
            'month' => today()->subDays(30),
            default => today()->subDays(7),
        };
        $to = today();

        $campaignFilter = null;
        if ($request->filled('campaign_key')) {
            $campaignFilter = WheelCampaign::where('campaign_key', $request->campaign_key)->first();
        }

        // Total de spins e prêmios
        $spinsQuery = \App\Models\WheelSpin::whereBetween('created_at', [$from, $to->endOfDay()])
            ->where('status', 'completed');
        if ($campaignFilter) {
            $spinsQuery->where('campaign_id', $campaignFilter->id);
        }

        $spins = $spinsQuery->with('prize')->get();
        $totalSpins = $spins->count();

        // Calcular valor total distribuído
        $totalValue = $spins->filter(fn($s) => $s->prize && $s->prize->estimated_value > 0)
            ->sum(fn($s) => $s->prize->estimated_value);

        // Jogadores únicos
        $uniquePlayers = $spins->pluck('player_id')->unique()->count();

        // Prêmios resgatados
        $redeemed = $spins->where('redeemed', true)->count();
        $redeemedValue = $spins->where('redeemed', true)
            ->filter(fn($s) => $s->prize && $s->prize->estimated_value > 0)
            ->sum(fn($s) => $s->prize->estimated_value);

        // Métricas calculadas
        $avgValuePerPlayer = $uniquePlayers > 0 ? round($totalValue / $uniquePlayers, 2) : 0;
        $costPerEngagement = $totalSpins > 0 ? round($totalValue / $totalSpins, 2) : 0;
        $costPerRedemption = $redeemed > 0 ? round($redeemedValue / $redeemed, 2) : 0;

        // Breakdown por tipo de prêmio
        $byPrizeType = $spins->filter(fn($s) => $s->prize)
            ->groupBy(fn($s) => $s->prize->type->value)
            ->map(fn($group, $type) => [
                'type' => $type,
                'count' => $group->count(),
                'value' => $group->sum(fn($s) => $s->prize->estimated_value ?? 0),
                'redeemed' => $group->where('redeemed', true)->count(),
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'campaign' => $campaignFilter ? [
                    'campaign_key' => $campaignFilter->campaign_key,
                    'name' => $campaignFilter->name,
                ] : null,
                'totals' => [
                    'spins' => $totalSpins,
                    'unique_players' => $uniquePlayers,
                    'prizes_distributed' => $spins->filter(fn($s) => $s->prize && $s->prize->requiresRedeem())->count(),
                    'prizes_redeemed' => $redeemed,
                    'total_value_distributed' => $totalValue,
                    'total_value_redeemed' => $redeemedValue,
                ],
                'metrics' => [
                    'avg_value_per_player' => $avgValuePerPlayer,
                    'cost_per_engagement' => $costPerEngagement,
                    'cost_per_redemption' => $costPerRedemption,
                    'redemption_rate' => $spins->filter(fn($s) => $s->prize && $s->prize->requiresRedeem())->count() > 0
                        ? round(($redeemed / $spins->filter(fn($s) => $s->prize && $s->prize->requiresRedeem())->count()) * 100, 1)
                        : 0,
                ],
                'by_prize_type' => $byPrizeType,
            ],
        ]);
    }
}

