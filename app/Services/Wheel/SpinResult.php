<?php

declare(strict_types=1);

namespace App\Services\Wheel;

use App\Models\WheelSegment;
use App\Models\WheelSpin;

/**
 * Resultado de um giro da roleta.
 */
class SpinResult
{
    public function __construct(
        public readonly WheelSpin $spin,
        public readonly WheelSegment $segment,
        public readonly ?int $targetIndex,
        public readonly ?float $finalAngle,
        public readonly ?string $prizeCode,
    ) {
    }

    /**
     * Dados para enviar à TV (COM target_prize_id).
     */
    public function forScreen(): array
    {
        return [
            'spin_key' => $this->spin->spin_key,
            'spin_id' => $this->spin->id,
            'target_segment_id' => $this->segment->id,
            'target_segment_index' => $this->targetIndex,
            'target_prize_id' => $this->segment->prize_id,
            'final_angle' => $this->finalAngle,
            'spin_duration_ms' => $this->spin->campaign->getSetting('spin_duration_ms', 8000),
            'server_time' => now()->toISOString(),
        ];
    }

    /**
     * Dados para enviar ao Mobile (SEM target_prize_id).
     */
    public function forMobile(): array
    {
        return [
            'spin_key' => $this->spin->spin_key,
            'spin_id' => $this->spin->id,
            'spin_duration_ms' => $this->spin->campaign->getSetting('spin_duration_ms', 8000),
            'server_time' => now()->toISOString(),
            // NÃO enviar target_prize_id, target_segment_id, final_angle
        ];
    }

    /**
     * Dados do resultado após animação.
     */
    public function forResult(): array
    {
        $prize = $this->segment->prize;

        return [
            'spin_key' => $this->spin->spin_key,
            'won' => $prize->requiresRedeem(),
            'prize' => [
                'prize_key' => $prize->prize_key,
                'name' => $prize->name,
                'type' => $prize->type->value,
                'icon' => $prize->icon ?? $prize->type->icon(),
                'description' => $prize->description,
                'redeem_instructions' => $prize->redeem_instructions,
            ],
            'prize_code' => $this->prizeCode,
            'segment' => [
                'segment_key' => $this->segment->segment_key,
                'label' => $this->segment->label,
                'color' => $this->segment->color,
            ],
        ];
    }
}
