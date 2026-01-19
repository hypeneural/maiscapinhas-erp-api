<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Wheel;

use App\Enums\CampaignStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wheel\StoreCampaignRequest;
use App\Http\Requests\Wheel\UpdateCampaignRequest;
use App\Http\Resources\Wheel\CampaignResource;
use App\Models\WheelCampaign;
use App\Models\WheelEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Wheel - Campaigns
 *
 * API para gerenciamento de Campanhas do módulo Roleta.
 */
class CampaignController extends Controller
{
    /**
     * Listar campanhas.
     */
    public function index(Request $request): JsonResponse
    {
        $query = WheelCampaign::query()
            ->withCount(['screens', 'activeSegments'])
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn($q) => $q->where(function ($sub) use ($request) {
                $sub->where('name', 'like', "%{$request->search}%")
                    ->orWhere('campaign_key', 'like', "%{$request->search}%");
            }))
            ->when($request->boolean('active_only'), fn($q) => $q->active())
            ->when($request->boolean('running_only'), fn($q) => $q->running())
            ->orderByRaw("FIELD(status, 'active', 'paused', 'draft', 'ended')")
            ->orderBy('created_at', 'desc');

        $campaigns = $request->boolean('all')
            ? $query->get()
            : $query->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => CampaignResource::collection($campaigns),
            'meta' => $campaigns instanceof \Illuminate\Pagination\LengthAwarePaginator ? [
                'total' => $campaigns->total(),
                'per_page' => $campaigns->perPage(),
                'current_page' => $campaigns->currentPage(),
            ] : null,
        ]);
    }

    /**
     * Criar campanha.
     */
    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Gerar campaign_key se não fornecido
        if (empty($data['campaign_key'])) {
            $data['campaign_key'] = WheelCampaign::generateCampaignKey();
        }

        // Merge settings com defaults
        $data['settings'] = array_merge(
            WheelCampaign::DEFAULT_SETTINGS,
            $data['settings'] ?? []
        );

        $campaign = WheelCampaign::create($data);

        WheelEvent::logConfigChanged($campaign, 'campaign_created');

        return response()->json([
            'success' => true,
            'message' => 'Campanha criada com sucesso.',
            'data' => new CampaignResource($campaign),
        ], 201);
    }

    /**
     * Exibir campanha.
     */
    public function show(string $campaignKey): JsonResponse
    {
        $campaign = WheelCampaign::where('campaign_key', $campaignKey)
            ->with(['activeSegments.prize', 'inventory.prize', 'screens'])
            ->withCount(['screens', 'activeSegments'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => new CampaignResource($campaign),
        ]);
    }

    /**
     * Atualizar campanha.
     */
    public function update(UpdateCampaignRequest $request, string $campaignKey): JsonResponse
    {
        $campaign = WheelCampaign::where('campaign_key', $campaignKey)->firstOrFail();

        // Não permitir edição de campanhas encerradas
        if ($campaign->status === CampaignStatus::ENDED) {
            return response()->json([
                'success' => false,
                'message' => 'Não é possível editar uma campanha encerrada.',
            ], 422);
        }

        $data = $request->validated();

        // Merge settings mantendo valores existentes
        if (isset($data['settings'])) {
            $data['settings'] = array_merge(
                $campaign->settings ?? WheelCampaign::DEFAULT_SETTINGS,
                $data['settings']
            );
        }

        $campaign->update($data);

        WheelEvent::logConfigChanged($campaign, 'campaign_updated', $data);

        return response()->json([
            'success' => true,
            'message' => 'Campanha atualizada com sucesso.',
            'data' => new CampaignResource($campaign->fresh()),
        ]);
    }

    /**
     * Excluir campanha.
     */
    public function destroy(string $campaignKey): JsonResponse
    {
        $campaign = WheelCampaign::where('campaign_key', $campaignKey)->firstOrFail();

        // Não permitir excluir campanhas ativas
        if ($campaign->status === CampaignStatus::ACTIVE) {
            return response()->json([
                'success' => false,
                'message' => 'Não é possível excluir uma campanha ativa. Pause ou encerre primeiro.',
            ], 422);
        }

        $campaign->delete();

        return response()->json([
            'success' => true,
            'message' => 'Campanha excluída com sucesso.',
        ]);
    }

    /**
     * Ativar campanha.
     */
    public function activate(string $campaignKey): JsonResponse
    {
        $campaign = WheelCampaign::where('campaign_key', $campaignKey)
            ->with('activeSegments.prize')
            ->firstOrFail();

        if (!$campaign->canActivate()) {
            $errors = [];

            if (!$campaign->status->canActivate()) {
                $errors[] = "Status atual ({$campaign->status->label()}) não permite ativação.";
            }

            if ($campaign->activeSegments()->count() === 0) {
                $errors[] = 'A campanha não possui segmentos ativos.';
            }

            $invalidSegments = $campaign->activeSegments()
                ->where('probability_weight', '<', 1)
                ->count();
            if ($invalidSegments > 0) {
                $errors[] = 'Existem segmentos com peso de probabilidade inválido (< 1).';
            }

            $inactivePrizes = $campaign->activeSegments()
                ->whereHas('prize', fn($q) => $q->where('active', false))
                ->count();
            if ($inactivePrizes > 0) {
                $errors[] = 'Existem segmentos apontando para prêmios inativos.';
            }

            return response()->json([
                'success' => false,
                'message' => 'Não é possível ativar a campanha.',
                'errors' => $errors,
            ], 422);
        }

        $campaign->activate();

        WheelEvent::logCampaignActivated($campaign);

        return response()->json([
            'success' => true,
            'message' => 'Campanha ativada com sucesso.',
            'data' => new CampaignResource($campaign->fresh()),
        ]);
    }

    /**
     * Pausar campanha.
     */
    public function pause(string $campaignKey): JsonResponse
    {
        $campaign = WheelCampaign::where('campaign_key', $campaignKey)->firstOrFail();

        if (!$campaign->pause()) {
            return response()->json([
                'success' => false,
                'message' => "Não é possível pausar uma campanha com status '{$campaign->status->label()}'.",
            ], 422);
        }

        WheelEvent::log(
            WheelEvent::TYPE_CAMPAIGN_PAUSED,
            ['campaign_key' => $campaign->campaign_key],
            null,
            $campaign->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Campanha pausada com sucesso.',
            'data' => new CampaignResource($campaign->fresh()),
        ]);
    }

    /**
     * Encerrar campanha.
     */
    public function end(string $campaignKey): JsonResponse
    {
        $campaign = WheelCampaign::where('campaign_key', $campaignKey)->firstOrFail();

        if (!$campaign->end()) {
            return response()->json([
                'success' => false,
                'message' => "Não é possível encerrar uma campanha com status '{$campaign->status->label()}'.",
            ], 422);
        }

        WheelEvent::log(
            WheelEvent::TYPE_CAMPAIGN_ENDED,
            ['campaign_key' => $campaign->campaign_key],
            null,
            $campaign->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Campanha encerrada com sucesso.',
            'data' => new CampaignResource($campaign->fresh()),
        ]);
    }
}
