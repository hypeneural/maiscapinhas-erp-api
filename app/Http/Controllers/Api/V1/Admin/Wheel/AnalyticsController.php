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
}

