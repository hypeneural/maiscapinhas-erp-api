<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Wheel;

use App\Http\Controllers\Controller;
use App\Models\WheelCampaign;
use App\Models\WheelPrize;
use App\Models\WheelPrizeRule;
use App\Models\WheelPrizeState;
use App\Services\Wheel\PrizeSelector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Wheel - Prize Rules (Admin)
 *
 * API para gerenciamento de Regras de Prêmios.
 */
class PrizeRuleController extends Controller
{
    public function __construct(
        private PrizeSelector $prizeSelector
    ) {
    }

    /**
     * Listar regras de uma campanha.
     */
    public function index(string $campaignKey): JsonResponse
    {
        $campaign = WheelCampaign::where('campaign_key', $campaignKey)->firstOrFail();

        $rules = WheelPrizeRule::where('campaign_id', $campaign->id)
            ->with('prize')
            ->orderBy('priority')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rules->map(fn($rule) => $this->formatRule($rule)),
        ]);
    }

    /**
     * Criar regra para um prêmio.
     */
    public function store(Request $request, string $campaignKey): JsonResponse
    {
        $campaign = WheelCampaign::where('campaign_key', $campaignKey)->firstOrFail();

        $validated = $request->validate([
            'prize_id' => 'required|exists:wheel_prizes,id',
            'min_gap_spins' => 'integer|min:0',
            'cooldown_seconds' => 'integer|min:0',
            'max_per_hour' => 'nullable|integer|min:1',
            'max_per_day' => 'nullable|integer|min:1',
            'cooldown_scope' => 'in:screen,campaign',
            'pacing_enabled' => 'boolean',
            'pacing_buffer' => 'numeric|min:1|max:5',
            'priority' => 'integer|min:1|max:1000',
            'active' => 'boolean',
        ]);

        // Verificar se já existe regra para este prêmio
        $existing = WheelPrizeRule::where('campaign_id', $campaign->id)
            ->where('prize_id', $validated['prize_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Já existe uma regra para este prêmio nesta campanha.',
                'code' => 'RULE_EXISTS',
            ], 409);
        }

        $rule = WheelPrizeRule::create([
            'campaign_id' => $campaign->id,
            ...$validated,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Regra criada com sucesso.',
            'data' => $this->formatRule($rule->load('prize')),
        ], 201);
    }

    /**
     * Exibir regra específica.
     */
    public function show(int $ruleId): JsonResponse
    {
        $rule = WheelPrizeRule::with('prize', 'campaign')->findOrFail($ruleId);

        // Buscar estado
        $state = WheelPrizeState::getOrCreate($rule->campaign_id, $rule->prize_id, null);

        return response()->json([
            'success' => true,
            'data' => [
                'rule' => $this->formatRule($rule),
                'state' => $state->toStateArray(0, $rule),
            ],
        ]);
    }

    /**
     * Atualizar regra.
     */
    public function update(Request $request, int $ruleId): JsonResponse
    {
        $rule = WheelPrizeRule::findOrFail($ruleId);

        $validated = $request->validate([
            'min_gap_spins' => 'integer|min:0',
            'cooldown_seconds' => 'integer|min:0',
            'max_per_hour' => 'nullable|integer|min:1',
            'max_per_day' => 'nullable|integer|min:1',
            'cooldown_scope' => 'in:screen,campaign',
            'pacing_enabled' => 'boolean',
            'pacing_buffer' => 'numeric|min:1|max:5',
            'priority' => 'integer|min:1|max:1000',
            'active' => 'boolean',
        ]);

        $rule->fill($validated);
        $rule->save();

        return response()->json([
            'success' => true,
            'message' => 'Regra atualizada com sucesso.',
            'data' => $this->formatRule($rule->fresh()->load('prize')),
        ]);
    }

    /**
     * Remover regra.
     */
    public function destroy(int $ruleId): JsonResponse
    {
        $rule = WheelPrizeRule::findOrFail($ruleId);
        $rule->delete();

        // Remover estados associados
        WheelPrizeState::where('campaign_id', $rule->campaign_id)
            ->where('prize_id', $rule->prize_id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Regra removida com sucesso.',
        ]);
    }

    /**
     * Reset cooldown de uma regra.
     */
    public function resetCooldown(Request $request, int $ruleId): JsonResponse
    {
        $rule = WheelPrizeRule::findOrFail($ruleId);

        $scopeId = $request->input('scope_id'); // null = global, ou screen_id

        $state = WheelPrizeState::where('campaign_id', $rule->campaign_id)
            ->where('prize_id', $rule->prize_id)
            ->where('scope_id', $scopeId)
            ->first();

        if ($state) {
            $state->resetCooldown();
        }

        return response()->json([
            'success' => true,
            'message' => 'Cooldown resetado com sucesso.',
        ]);
    }

    /**
     * Ver estado de todos os prêmios da campanha.
     */
    public function prizeState(Request $request, string $campaignKey): JsonResponse
    {
        $campaign = WheelCampaign::where('campaign_key', $campaignKey)->firstOrFail();

        $screenId = $request->input('screen_id') ? (int) $request->input('screen_id') : null;
        $spinSeq = (int) $request->input('spin_seq', 0);

        $status = $this->prizeSelector->getEligibilityStatus($campaign, $screenId, $spinSeq);

        return response()->json([
            'success' => true,
            'data' => $status,
            'meta' => [
                'campaign_key' => $campaign->campaign_key,
                'screen_id' => $screenId,
                'current_spin_seq' => $spinSeq,
                'timestamp' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Bulk update de regras (criar/atualizar várias de uma vez).
     */
    public function bulkUpdate(Request $request, string $campaignKey): JsonResponse
    {
        $campaign = WheelCampaign::where('campaign_key', $campaignKey)->firstOrFail();

        $validated = $request->validate([
            'rules' => 'required|array',
            'rules.*.prize_id' => 'required|exists:wheel_prizes,id',
            'rules.*.min_gap_spins' => 'integer|min:0',
            'rules.*.cooldown_seconds' => 'integer|min:0',
            'rules.*.max_per_hour' => 'nullable|integer|min:1',
            'rules.*.max_per_day' => 'nullable|integer|min:1',
            'rules.*.cooldown_scope' => 'in:screen,campaign',
            'rules.*.pacing_enabled' => 'boolean',
            'rules.*.pacing_buffer' => 'numeric|min:1|max:5',
            'rules.*.priority' => 'integer|min:1|max:1000',
            'rules.*.active' => 'boolean',
        ]);

        $created = 0;
        $updated = 0;

        foreach ($validated['rules'] as $ruleData) {
            $existing = WheelPrizeRule::where('campaign_id', $campaign->id)
                ->where('prize_id', $ruleData['prize_id'])
                ->first();

            if ($existing) {
                $existing->fill($ruleData);
                $existing->save();
                $updated++;
            } else {
                WheelPrizeRule::create([
                    'campaign_id' => $campaign->id,
                    ...$ruleData,
                ]);
                $created++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Regras atualizadas: {$created} criadas, {$updated} atualizadas.",
            'data' => [
                'created' => $created,
                'updated' => $updated,
            ],
        ]);
    }

    /**
     * Formata regra para response.
     */
    private function formatRule(WheelPrizeRule $rule): array
    {
        return [
            'id' => $rule->id,
            'campaign_id' => $rule->campaign_id,
            'prize_id' => $rule->prize_id,
            'prize' => $rule->prize ? [
                'prize_key' => $rule->prize->prize_key,
                'name' => $rule->prize->name,
                'type' => $rule->prize->type->value,
                'icon' => $rule->prize->icon,
            ] : null,
            'min_gap_spins' => $rule->min_gap_spins,
            'cooldown_seconds' => $rule->cooldown_seconds,
            'max_per_hour' => $rule->max_per_hour,
            'max_per_day' => $rule->max_per_day,
            'cooldown_scope' => $rule->cooldown_scope,
            'pacing_enabled' => $rule->pacing_enabled,
            'pacing_buffer' => (float) $rule->pacing_buffer,
            'priority' => $rule->priority,
            'active' => $rule->active,
            'summary' => $rule->getSummary(),
            'created_at' => $rule->created_at?->toISOString(),
            'updated_at' => $rule->updated_at?->toISOString(),
        ];
    }
}
