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
}
