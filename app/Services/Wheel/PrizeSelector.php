<?php

declare(strict_types=1);

namespace App\Services\Wheel;

use App\Models\WheelCampaign;
use App\Models\WheelInventory;
use App\Models\WheelPrize;
use App\Models\WheelPrizeRule;
use App\Models\WheelPrizeState;
use App\Models\WheelSegment;
use App\Models\WheelSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * PrizeSelector - Motor de seleção de prêmios com regras avançadas.
 * 
 * Algoritmo:
 * 1. Carrega segmentos ativos
 * 2. Filtra por elegibilidade (estoque, cooldown, limites, pacing)
 * 3. Fallback para prêmio "safe" se nenhum elegível
 * 4. Sorteio ponderado com random_int (criptograficamente seguro)
 */
class PrizeSelector
{
    /**
     * Seleciona segmento elegível com base nas regras.
     */
    public function selectEligibleSegment(
        WheelCampaign $campaign,
        WheelSession $session,
        ?int $screenId = null
    ): ?WheelSegment {
        // 1. Carregar segmentos ativos com prize e rules
        $segments = $campaign->activeSegments()
            ->with(['prize'])
            ->orderBy('sort_order')
            ->get();

        if ($segments->isEmpty()) {
            Log::warning('No active segments for campaign', ['campaign_id' => $campaign->id]);
            return null;
        }

        // Carregar regras da campanha
        $rules = WheelPrizeRule::where('campaign_id', $campaign->id)
            ->where('active', true)
            ->get()
            ->keyBy('prize_id');

        // Próxima sequência de spin
        $currentSpinSeq = ($session->spin_seq ?? 0) + 1;

        // 2. Filtrar por elegibilidade
        $eligible = $segments->filter(function ($segment) use ($campaign, $rules, $screenId, $currentSpinSeq) {
            return $this->isSegmentEligible(
                $segment,
                $campaign,
                $rules->get($segment->prize_id),
                $screenId,
                $currentSpinSeq
            );
        });

        // 3. Se nenhum elegível, fallback
        if ($eligible->isEmpty()) {
            Log::info('No eligible segments, using fallback', [
                'campaign_id' => $campaign->id,
                'total_segments' => $segments->count(),
            ]);
            return $this->getSafeFallback($segments);
        }

        // 4. Sorteio ponderado
        return $this->weightedPick($eligible);
    }

    /**
     * Verifica se um segmento está elegível.
     */
    private function isSegmentEligible(
        WheelSegment $segment,
        WheelCampaign $campaign,
        ?WheelPrizeRule $rule,
        ?int $screenId,
        int $currentSpinSeq
    ): bool {
        $prize = $segment->prize;

        // a) Prêmio ativo?
        if (!$prize->active) {
            return false;
        }

        // b) Tem estoque?
        if (!$this->hasStock($campaign->id, $prize->id)) {
            return false;
        }

        // Se não tem regra específica, está elegível
        if (!$rule) {
            return true;
        }

        // Determinar scope_id
        $scopeId = $rule->isScopedByScreen() ? $screenId : null;

        // Buscar estado
        $state = WheelPrizeState::getOrCreate($campaign->id, $prize->id, $scopeId);

        // c) Respeita cooldown por spins?
        if ($rule->hasSpinCooldown()) {
            if (!$state->respectsSpinCooldown($currentSpinSeq, $rule->min_gap_spins)) {
                return false;
            }
        }

        // d) Respeita cooldown por tempo?
        if ($rule->hasTimeCooldown()) {
            if (!$state->respectsTimeCooldown($rule->cooldown_seconds)) {
                return false;
            }
        }

        // e) Respeita limite por hora?
        if ($rule->hasHourlyLimit()) {
            if (!$state->respectsHourlyLimit($rule->max_per_hour)) {
                return false;
            }
        }

        // f) Respeita limite por dia?
        if ($rule->hasDailyLimit()) {
            if (!$state->respectsDailyLimit($rule->max_per_day)) {
                return false;
            }
        }

        // g) Respeita pacing?
        if ($rule->pacing_enabled) {
            if (!$this->respectsPacing($campaign, $prize, $state, $rule)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verifica estoque disponível.
     */
    private function hasStock(int $campaignId, int $prizeId): bool
    {
        $prize = WheelPrize::find($prizeId);

        if (!$prize || !$prize->consumesInventory()) {
            return true; // Prêmios que não consomem estoque sempre disponíveis
        }

        $inventory = WheelInventory::where('campaign_id', $campaignId)
            ->where('prize_id', $prizeId)
            ->first();

        if (!$inventory) {
            return true; // Sem controle = disponível
        }

        $inventory->autoResetIfNeeded();
        return $inventory->hasStock();
    }

    /**
     * Verifica pacing (distribuição ao longo da campanha).
     */
    private function respectsPacing(
        WheelCampaign $campaign,
        WheelPrize $prize,
        WheelPrizeState $state,
        WheelPrizeRule $rule
    ): bool {
        // Precisa de período definido na campanha
        if (!$campaign->starts_at || !$campaign->ends_at) {
            return true;
        }

        // Calcular progresso da campanha
        $totalDuration = $campaign->starts_at->diffInSeconds($campaign->ends_at);
        $elapsed = $campaign->starts_at->diffInSeconds(now());
        $progress = min(1, max(0, $elapsed / $totalDuration));

        // Buscar estoque total do prêmio
        $inventory = WheelInventory::where('campaign_id', $campaign->id)
            ->where('prize_id', $prize->id)
            ->first();

        if (!$inventory || !$inventory->quantity) {
            return true; // Sem estoque definido
        }

        // Calcular ritmo ideal
        $idealUsed = $inventory->quantity * $progress;
        $maxAllowed = $idealUsed * $rule->pacing_buffer;

        // Já ultrapassou o buffer?
        return $state->awarded_count_total <= $maxAllowed;
    }

    /**
     * Retorna prêmio de fallback (nothing/try_again).
     */
    private function getSafeFallback(Collection $segments): ?WheelSegment
    {
        // Priorizar prêmios que não consomem estoque
        $fallback = $segments->first(fn($s) => !$s->prize->consumesInventory());

        if ($fallback) {
            return $fallback;
        }

        // Último recurso: qualquer segmento com estoque
        return $segments->first(fn($s) => $this->hasStock($s->campaign_id, $s->prize_id));
    }

    /**
     * Sorteio ponderado com RNG criptograficamente seguro.
     */
    private function weightedPick(Collection $segments): WheelSegment
    {
        $totalWeight = $segments->sum('probability_weight');

        if ($totalWeight <= 0) {
            return $segments->first();
        }

        // random_int é criptograficamente seguro
        $random = random_int(1, $totalWeight);
        $cumulative = 0;

        foreach ($segments as $segment) {
            $cumulative += $segment->probability_weight;
            if ($random <= $cumulative) {
                return $segment;
            }
        }

        return $segments->first();
    }

    /**
     * Registra que um prêmio foi sorteado (atualiza estado).
     */
    public function recordPrizeAwarded(
        int $campaignId,
        int $prizeId,
        int $spinSeq,
        ?int $screenId = null
    ): void {
        // Buscar regra para determinar scope
        $rule = WheelPrizeRule::where('campaign_id', $campaignId)
            ->where('prize_id', $prizeId)
            ->where('active', true)
            ->first();

        $scopeId = $rule && $rule->isScopedByScreen() ? $screenId : null;

        // Atualizar estado
        $state = WheelPrizeState::getOrCreate($campaignId, $prizeId, $scopeId);
        $state->recordAward($spinSeq);

        // Se scope é por screen, também atualizar estado global
        if ($scopeId !== null) {
            $globalState = WheelPrizeState::getOrCreate($campaignId, $prizeId, null);
            $globalState->recordAward($spinSeq);
        }
    }

    /**
     * Retorna status de elegibilidade de todos os prêmios.
     */
    public function getEligibilityStatus(
        WheelCampaign $campaign,
        ?int $screenId = null,
        ?int $currentSpinSeq = null
    ): array {
        $segments = $campaign->activeSegments()
            ->with('prize')
            ->orderBy('sort_order')
            ->get();

        $rules = WheelPrizeRule::where('campaign_id', $campaign->id)
            ->where('active', true)
            ->get()
            ->keyBy('prize_id');

        $spinSeq = $currentSpinSeq ?? 0;

        return $segments->map(function ($segment) use ($campaign, $rules, $screenId, $spinSeq) {
            $prize = $segment->prize;
            $rule = $rules->get($prize->id);
            $scopeId = $rule && $rule->isScopedByScreen() ? $screenId : null;
            $state = WheelPrizeState::getOrCreate($campaign->id, $prize->id, $scopeId);

            $isEligible = $this->isSegmentEligible($segment, $campaign, $rule, $screenId, $spinSeq);

            // Determinar motivo se não elegível
            $reason = null;
            if (!$isEligible) {
                if (!$prize->active) {
                    $reason = 'Prêmio inativo';
                } elseif (!$this->hasStock($campaign->id, $prize->id)) {
                    $reason = 'Sem estoque';
                } elseif ($rule) {
                    if ($rule->hasSpinCooldown() && !$state->respectsSpinCooldown($spinSeq, $rule->min_gap_spins)) {
                        $remaining = $state->getSpinsUntilEligible($spinSeq, $rule->min_gap_spins);
                        $reason = "Cooldown: faltam {$remaining} spins";
                    } elseif ($rule->hasTimeCooldown() && !$state->respectsTimeCooldown($rule->cooldown_seconds)) {
                        $remaining = $state->getSecondsUntilEligible($rule->cooldown_seconds);
                        $reason = "Cooldown: faltam " . ceil($remaining / 60) . " min";
                    } elseif ($rule->hasHourlyLimit() && !$state->respectsHourlyLimit($rule->max_per_hour)) {
                        $reason = "Limite hora: {$state->awarded_count_hour}/{$rule->max_per_hour}";
                    } elseif ($rule->hasDailyLimit() && !$state->respectsDailyLimit($rule->max_per_day)) {
                        $reason = "Limite dia: {$state->awarded_count_day}/{$rule->max_per_day}";
                    } elseif ($rule->pacing_enabled) {
                        $reason = 'Pacing: ritmo ultrapassado';
                    }
                }
            }

            // Buscar inventário
            $inventory = WheelInventory::where('campaign_id', $campaign->id)
                ->where('prize_id', $prize->id)
                ->first();

            return [
                'prize_key' => $prize->prize_key,
                'prize_name' => $prize->name,
                'segment_label' => $segment->label,
                'probability_weight' => $segment->probability_weight,
                'is_eligible' => $isEligible,
                'reason' => $reason,
                'rule' => $rule ? $rule->getSummary() : null,
                'state' => $state->toStateArray($spinSeq, $rule),
                'inventory' => $inventory ? [
                    'total' => $inventory->quantity,
                    'remaining' => $inventory->remaining,
                    'daily_limit' => $inventory->daily_limit,
                    'daily_remaining' => $inventory->daily_remaining,
                ] : null,
            ];
        })->values()->toArray();
    }
}
