<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Wheel;

use App\Http\Controllers\Controller;
use App\Http\Resources\Wheel\InventoryResource;
use App\Models\WheelCampaign;
use App\Models\WheelInventory;
use App\Models\WheelPrize;
use App\Models\WheelEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Wheel - Inventory
 *
 * API para gerenciamento de Inventário/Estoque de prêmios do módulo Roleta.
 */
class InventoryController extends Controller
{
    /**
     * Listar inventário de uma campanha.
     */
    public function index(string $campaignKey): JsonResponse
    {
        $campaign = WheelCampaign::where('campaign_key', $campaignKey)->firstOrFail();

        $inventory = $campaign->inventory()
            ->with('prize')
            ->get();

        return response()->json([
            'success' => true,
            'data' => InventoryResource::collection($inventory),
        ]);
    }

    /**
     * Sincronizar inventário (batch update).
     */
    public function sync(Request $request, string $campaignKey): JsonResponse
    {
        $request->validate([
            'inventory' => 'required|array',
            'inventory.*.prize_id' => 'required|exists:wheel_prizes,id',
            'inventory.*.total_limit' => 'nullable|integer|min:0',
            'inventory.*.remaining' => 'nullable|integer|min:0',
            'inventory.*.daily_limit' => 'nullable|integer|min:0',
            'inventory.*.daily_remaining' => 'nullable|integer|min:0',
        ]);

        $campaign = WheelCampaign::where('campaign_key', $campaignKey)->firstOrFail();

        foreach ($request->inventory as $item) {
            $data = [
                'total_limit' => $item['total_limit'] ?? null,
                'remaining' => $item['remaining'] ?? $item['total_limit'] ?? null,
                'daily_limit' => $item['daily_limit'] ?? null,
                'daily_remaining' => $item['daily_remaining'] ?? $item['daily_limit'] ?? null,
            ];

            // Validar que remaining não excede total_limit
            if ($data['total_limit'] !== null && $data['remaining'] > $data['total_limit']) {
                $data['remaining'] = $data['total_limit'];
            }

            // Validar que daily_remaining não excede daily_limit
            if ($data['daily_limit'] !== null && $data['daily_remaining'] > $data['daily_limit']) {
                $data['daily_remaining'] = $data['daily_limit'];
            }

            WheelInventory::updateOrCreate(
                [
                    'campaign_id' => $campaign->id,
                    'prize_id' => $item['prize_id'],
                ],
                $data
            );
        }

        WheelEvent::logConfigChanged($campaign, 'inventory_updated', [
            'count' => count($request->inventory),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Inventário atualizado com sucesso.',
            'data' => InventoryResource::collection(
                $campaign->inventory()->with('prize')->get()
            ),
        ]);
    }

    /**
     * Adicionar estoque a um prêmio.
     */
    public function addStock(Request $request, string $campaignKey, string $prizeKey): JsonResponse
    {
        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $campaign = WheelCampaign::where('campaign_key', $campaignKey)->firstOrFail();
        $prize = WheelPrize::where('prize_key', $prizeKey)->firstOrFail();

        $inventory = WheelInventory::firstOrCreate(
            [
                'campaign_id' => $campaign->id,
                'prize_id' => $prize->id,
            ],
            [
                'total_limit' => null,
                'remaining' => 0,
            ]
        );

        $oldRemaining = $inventory->remaining ?? 0;
        $inventory->addStock($request->quantity);

        WheelEvent::logConfigChanged($campaign, 'stock_added', [
            'prize_key' => $prize->prize_key,
            'quantity' => $request->quantity,
            'old_remaining' => $oldRemaining,
            'new_remaining' => $inventory->remaining,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Adicionadas {$request->quantity} unidade(s) ao estoque.",
            'data' => new InventoryResource($inventory->fresh('prize')),
        ]);
    }

    /**
     * Resetar limite diário de um prêmio.
     */
    public function resetDaily(string $campaignKey, string $prizeKey): JsonResponse
    {
        $campaign = WheelCampaign::where('campaign_key', $campaignKey)->firstOrFail();
        $prize = WheelPrize::where('prize_key', $prizeKey)->firstOrFail();

        $inventory = WheelInventory::where('campaign_id', $campaign->id)
            ->where('prize_id', $prize->id)
            ->firstOrFail();

        if ($inventory->daily_limit === null) {
            return response()->json([
                'success' => false,
                'message' => 'Este prêmio não possui limite diário configurado.',
            ], 422);
        }

        $inventory->resetDaily();

        WheelEvent::logConfigChanged($campaign, 'daily_reset', [
            'prize_key' => $prize->prize_key,
            'daily_limit' => $inventory->daily_limit,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Limite diário resetado com sucesso.',
            'data' => new InventoryResource($inventory->fresh('prize')),
        ]);
    }
}
