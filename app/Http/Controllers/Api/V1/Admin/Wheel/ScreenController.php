<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Wheel;

use App\Enums\ScreenStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wheel\StoreScreenRequest;
use App\Http\Requests\Wheel\UpdateScreenRequest;
use App\Http\Resources\Wheel\ScreenResource;
use App\Models\WheelScreen;
use App\Models\WheelCampaign;
use App\Models\WheelScreenCampaign;
use App\Models\WheelEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Wheel - Screens (TVs)
 *
 * API para gerenciamento de TVs/Totens do módulo Roleta.
 */
class ScreenController extends Controller
{
    /**
     * Listar TVs.
     * 
     * Retorna lista paginada de TVs com filtros opcionais.
     */
    public function index(Request $request): JsonResponse
    {
        $query = WheelScreen::query()
            ->with(['store', 'activeCampaigns'])
            ->when($request->filled('store_id'), fn($q) => $q->where('store_id', $request->store_id))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn($q) => $q->where(function ($sub) use ($request) {
                $sub->where('name', 'like', "%{$request->search}%")
                    ->orWhere('screen_key', 'like', "%{$request->search}%");
            }))
            ->when($request->boolean('online_only'), fn($q) => $q->online())
            ->when($request->boolean('offline_only'), fn($q) => $q->offline())
            ->orderBy('store_id')
            ->orderBy('name');

        $screens = $request->boolean('all')
            ? $query->get()
            : $query->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => ScreenResource::collection($screens),
            'meta' => $screens instanceof \Illuminate\Pagination\LengthAwarePaginator ? [
                'total' => $screens->total(),
                'per_page' => $screens->perPage(),
                'current_page' => $screens->currentPage(),
            ] : null,
        ]);
    }

    /**
     * Criar TV.
     */
    public function store(StoreScreenRequest $request): JsonResponse
    {
        $screen = WheelScreen::create($request->validated());

        // Gerar token inicial
        $plainToken = $screen->rotateSecretToken();

        WheelEvent::logScreenConnected($screen, ['action' => 'created']);

        return response()->json([
            'success' => true,
            'message' => 'TV criada com sucesso.',
            'data' => new ScreenResource($screen),
            'secret_token' => $plainToken, // Exibido apenas 1x
        ], 201);
    }

    /**
     * Exibir TV.
     */
    public function show(string $screenKey): JsonResponse
    {
        $screen = WheelScreen::where('screen_key', $screenKey)
            ->with(['store', 'campaigns.campaign', 'activeCampaigns'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => new ScreenResource($screen),
        ]);
    }

    /**
     * Atualizar TV.
     */
    public function update(UpdateScreenRequest $request, string $screenKey): JsonResponse
    {
        $screen = WheelScreen::where('screen_key', $screenKey)->firstOrFail();

        $screen->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'TV atualizada com sucesso.',
            'data' => new ScreenResource($screen->fresh(['store', 'activeCampaigns'])),
        ]);
    }

    /**
     * Excluir TV.
     */
    public function destroy(string $screenKey): JsonResponse
    {
        $screen = WheelScreen::where('screen_key', $screenKey)->firstOrFail();

        $screen->delete();

        return response()->json([
            'success' => true,
            'message' => 'TV excluída com sucesso.',
        ]);
    }

    /**
     * Gerar novo token de autenticação.
     * 
     * O token atual será invalidado. A TV precisará ser reconfigurada.
     * O novo token é exibido apenas 1x.
     */
    public function rotateSecret(string $screenKey): JsonResponse
    {
        $screen = WheelScreen::where('screen_key', $screenKey)->firstOrFail();

        $plainToken = $screen->rotateSecretToken();

        WheelEvent::log(
            WheelEvent::TYPE_CONFIG_CHANGED,
            ['action' => 'secret_rotated'],
            $screen->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Novo token gerado com sucesso. Salve-o agora, pois não será exibido novamente.',
            'secret_token' => $plainToken,
        ]);
    }

    /**
     * Alterar status da TV.
     */
    public function setStatus(Request $request, string $screenKey): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:' . implode(',', ScreenStatus::values()),
        ]);

        $screen = WheelScreen::where('screen_key', $screenKey)->firstOrFail();

        $oldStatus = $screen->status;
        $screen->status = ScreenStatus::from($request->status);
        $screen->save();

        WheelEvent::log(
            WheelEvent::TYPE_CONFIG_CHANGED,
            [
                'action' => 'status_changed',
                'old_status' => $oldStatus->value,
                'new_status' => $request->status,
            ],
            $screen->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Status atualizado para: ' . $screen->status->label(),
            'data' => new ScreenResource($screen->fresh()),
        ]);
    }

    /**
     * Health check da TV.
     * 
     * Retorna informações de saúde: último heartbeat, status, campanha ativa.
     */
    public function health(string $screenKey): JsonResponse
    {
        $screen = WheelScreen::where('screen_key', $screenKey)
            ->with(['store', 'activeCampaigns'])
            ->firstOrFail();

        $activeCampaign = $screen->getActiveCampaign();

        return response()->json([
            'success' => true,
            'data' => [
                'screen_key' => $screen->screen_key,
                'name' => $screen->name,
                'store' => $screen->store?->name,
                'status' => $screen->status->value,
                'status_label' => $screen->status->label(),
                'is_online' => $screen->isOnline(),
                'last_seen_at' => $screen->last_seen_at?->toISOString(),
                'last_seen_ago' => $screen->last_seen_at?->diffForHumans(),
                'device_info' => $screen->device_info,
                'active_campaign' => $activeCampaign ? [
                    'campaign_key' => $activeCampaign->campaign_key,
                    'name' => $activeCampaign->name,
                    'status' => $activeCampaign->status->value,
                ] : null,
            ],
        ]);
    }

    /**
     * Listar campanhas vinculadas à TV.
     */
    public function campaigns(string $screenKey): JsonResponse
    {
        $screen = WheelScreen::where('screen_key', $screenKey)->firstOrFail();

        $campaigns = $screen->campaigns()
            ->withPivot('status')
            ->orderByPivot('status', 'desc') // active first
            ->get();

        return response()->json([
            'success' => true,
            'data' => $campaigns->map(fn($c) => [
                'campaign_key' => $c->campaign_key,
                'name' => $c->name,
                'campaign_status' => $c->status->value,
                'link_status' => $c->pivot->status,
                'is_active' => $c->pivot->status === 'active',
            ]),
        ]);
    }

    /**
     * Sincronizar campanhas da TV.
     */
    public function syncCampaigns(Request $request, string $screenKey): JsonResponse
    {
        $request->validate([
            'campaigns' => 'required|array',
            'campaigns.*.campaign_id' => 'required|exists:wheel_campaigns,id',
            'campaigns.*.status' => 'required|in:active,inactive',
        ]);

        $screen = WheelScreen::where('screen_key', $screenKey)->firstOrFail();

        // Garantir que apenas uma campanha esteja ativa
        $activeCount = collect($request->campaigns)->where('status', 'active')->count();
        if ($activeCount > 1) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas uma campanha pode estar ativa por TV.',
            ], 422);
        }

        // Sync
        $syncData = [];
        foreach ($request->campaigns as $item) {
            $syncData[$item['campaign_id']] = ['status' => $item['status']];
        }

        $screen->campaigns()->sync($syncData);

        return response()->json([
            'success' => true,
            'message' => 'Campanhas sincronizadas com sucesso.',
        ]);
    }

    /**
     * Ativar uma campanha específica na TV.
     * 
     * Desativa outras campanhas vinculadas.
     */
    public function activateCampaign(string $screenKey, string $campaignKey): JsonResponse
    {
        $screen = WheelScreen::where('screen_key', $screenKey)->firstOrFail();
        $campaign = WheelCampaign::where('campaign_key', $campaignKey)->firstOrFail();

        // Verificar se a campanha está vinculada
        $pivot = WheelScreenCampaign::where('screen_id', $screen->id)
            ->where('campaign_id', $campaign->id)
            ->first();

        if (!$pivot) {
            // Criar vínculo
            $pivot = WheelScreenCampaign::create([
                'screen_id' => $screen->id,
                'campaign_id' => $campaign->id,
                'status' => 'inactive',
            ]);
        }

        // Desativar outras campanhas da TV
        WheelScreenCampaign::where('screen_id', $screen->id)
            ->where('campaign_id', '!=', $campaign->id)
            ->update(['status' => 'inactive']);

        // Ativar esta
        $pivot->status = 'active';
        $pivot->save();

        WheelEvent::logCampaignActivated($campaign, $screen->id);

        return response()->json([
            'success' => true,
            'message' => "Campanha '{$campaign->name}' ativada na TV '{$screen->name}'.",
        ]);
    }
}
