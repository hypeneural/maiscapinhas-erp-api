<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Wheel;

use App\Http\Controllers\Controller;
use App\Models\WheelEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Wheel - Events/Logs
 *
 * API para visualização de logs de eventos do módulo Roleta.
 */
class EventController extends Controller
{
    /**
     * Listar eventos/logs.
     */
    public function index(Request $request): JsonResponse
    {
        $query = WheelEvent::query()
            ->with(['screen:id,screen_key,name', 'campaign:id,campaign_key,name'])
            ->when($request->filled('type'), fn($q) => $q->byType($request->type))
            ->when($request->filled('screen_id'), fn($q) => $q->byScreen($request->screen_id))
            ->when($request->filled('campaign_id'), fn($q) => $q->byCampaign($request->campaign_id))
            ->when(
                $request->filled('from') && $request->filled('to'),
                fn($q) =>
                $q->inDateRange($request->from, $request->to)
            )
            ->when($request->filled('hours'), fn($q) => $q->recent((int) $request->hours))
            ->orderBy('created_at', 'desc');

        $events = $query->paginate($request->input('per_page', 50));

        return response()->json([
            'success' => true,
            'data' => $events->map(fn($e) => [
                'id' => $e->id,
                'event_id' => $e->event_id,
                'type' => $e->type,
                'screen' => $e->screen ? [
                    'screen_key' => $e->screen->screen_key,
                    'name' => $e->screen->name,
                ] : null,
                'campaign' => $e->campaign ? [
                    'campaign_key' => $e->campaign->campaign_key,
                    'name' => $e->campaign->name,
                ] : null,
                'payload' => $e->payload,
                'created_at' => $e->created_at->toISOString(),
                'created_at_human' => $e->created_at->diffForHumans(),
            ]),
            'meta' => [
                'total' => $events->total(),
                'per_page' => $events->perPage(),
                'current_page' => $events->currentPage(),
            ],
        ]);
    }
}
