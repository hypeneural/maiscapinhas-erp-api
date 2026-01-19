<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Wheel;

use App\Http\Controllers\Controller;
use App\Http\Resources\Wheel\SegmentResource;
use App\Models\WheelCampaign;
use App\Models\WheelSegment;
use App\Models\WheelPrize;
use App\Models\WheelEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * @group Wheel - Segments
 *
 * API para gerenciamento de Segmentos (fatias da roleta) do módulo Roleta.
 */
class SegmentController extends Controller
{
    /**
     * Listar segmentos de uma campanha.
     */
    public function index(string $campaignKey): JsonResponse
    {
        $campaign = WheelCampaign::where('campaign_key', $campaignKey)->firstOrFail();

        $segments = $campaign->segments()
            ->with('prize')
            ->orderBy('sort_order')
            ->get();

        $totalWeight = $segments->where('active', true)->sum('probability_weight');

        return response()->json([
            'success' => true,
            'data' => SegmentResource::collection($segments),
            'meta' => [
                'total_segments' => $segments->count(),
                'active_segments' => $segments->where('active', true)->count(),
                'total_weight' => $totalWeight,
            ],
        ]);
    }

    /**
     * Sincronizar segmentos (salvar lista completa).
     * 
     * Útil para reordenação via drag-and-drop.
     */
    public function sync(Request $request, string $campaignKey): JsonResponse
    {
        $request->validate([
            'segments' => 'required|array|min:1',
            'segments.*.id' => 'nullable|integer',
            'segments.*.segment_key' => 'nullable|string|max:50',
            'segments.*.label' => 'required|string|max:50',
            'segments.*.color' => 'required|string|max:20',
            'segments.*.prize_id' => 'required|exists:wheel_prizes,id',
            'segments.*.probability_weight' => 'required|integer|min:1',
            'segments.*.active' => 'boolean',
        ]);

        $campaign = WheelCampaign::where('campaign_key', $campaignKey)->firstOrFail();

        // IDs que serão mantidos
        $keepIds = collect($request->segments)
            ->pluck('id')
            ->filter()
            ->toArray();

        // Remover segmentos que não estão na lista
        $campaign->segments()
            ->whereNotIn('id', $keepIds)
            ->delete();

        // Criar/Atualizar segmentos
        foreach ($request->segments as $index => $segmentData) {
            $data = [
                'campaign_id' => $campaign->id,
                'label' => $segmentData['label'],
                'color' => $segmentData['color'],
                'prize_id' => $segmentData['prize_id'],
                'probability_weight' => $segmentData['probability_weight'],
                'sort_order' => $index,
                'active' => $segmentData['active'] ?? true,
            ];

            if (!empty($segmentData['id'])) {
                // Atualizar existente
                WheelSegment::where('id', $segmentData['id'])
                    ->where('campaign_id', $campaign->id)
                    ->update($data);
            } else {
                // Criar novo
                $data['segment_key'] = $segmentData['segment_key']
                    ?? 'seg_' . Str::random(8);
                WheelSegment::create($data);
            }
        }

        WheelEvent::logConfigChanged($campaign, 'segments_synced', [
            'count' => count($request->segments),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Segmentos salvos com sucesso.',
            'data' => SegmentResource::collection(
                $campaign->segments()->with('prize')->orderBy('sort_order')->get()
            ),
        ]);
    }

    /**
     * Criar segmento.
     */
    public function store(Request $request, string $campaignKey): JsonResponse
    {
        $request->validate([
            'label' => 'required|string|max:50',
            'color' => 'required|string|max:20',
            'prize_id' => 'required|exists:wheel_prizes,id',
            'probability_weight' => 'required|integer|min:1',
            'active' => 'boolean',
        ]);

        $campaign = WheelCampaign::where('campaign_key', $campaignKey)->firstOrFail();

        // Pegar próximo sort_order
        $maxOrder = $campaign->segments()->max('sort_order') ?? -1;

        $segment = WheelSegment::create([
            'campaign_id' => $campaign->id,
            'segment_key' => 'seg_' . Str::random(8),
            'label' => $request->label,
            'color' => $request->color,
            'prize_id' => $request->prize_id,
            'probability_weight' => $request->probability_weight,
            'sort_order' => $maxOrder + 1,
            'active' => $request->boolean('active', true),
        ]);

        $segment->load('prize');

        return response()->json([
            'success' => true,
            'message' => 'Segmento criado com sucesso.',
            'data' => new SegmentResource($segment),
        ], 201);
    }

    /**
     * Excluir segmento.
     */
    public function destroy(string $campaignKey, string $segmentKey): JsonResponse
    {
        $campaign = WheelCampaign::where('campaign_key', $campaignKey)->firstOrFail();

        $segment = WheelSegment::where('campaign_id', $campaign->id)
            ->where('segment_key', $segmentKey)
            ->firstOrFail();

        $segment->delete();

        // Reordenar restantes
        $campaign->segments()
            ->orderBy('sort_order')
            ->get()
            ->each(function ($seg, $index) {
                $seg->update(['sort_order' => $index]);
            });

        return response()->json([
            'success' => true,
            'message' => 'Segmento excluído com sucesso.',
        ]);
    }

    /**
     * Reordenar segmentos.
     * 
     * Recebe array de IDs na nova ordem desejada.
     */
    public function reorder(Request $request, string $campaignKey): JsonResponse
    {
        $request->validate([
            'order' => 'required|array|min:1',
            'order.*' => 'integer',
        ]);

        $campaign = WheelCampaign::where('campaign_key', $campaignKey)->firstOrFail();

        // Reordenar
        foreach ($request->order as $index => $segmentId) {
            WheelSegment::where('id', $segmentId)
                ->where('campaign_id', $campaign->id)
                ->update(['sort_order' => $index]);
        }

        WheelEvent::logConfigChanged($campaign, 'segments_reordered', [
            'order' => $request->order,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Segmentos reordenados com sucesso.',
            'data' => SegmentResource::collection(
                $campaign->segments()->with('prize')->orderBy('sort_order')->get()
            ),
        ]);
    }
}

