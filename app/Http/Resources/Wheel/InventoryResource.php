<?php

declare(strict_types=1);

namespace App\Http\Resources\Wheel;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'prize_id' => $this->prize_id,
            'prize' => $this->whenLoaded('prize', fn() => [
                'id' => $this->prize->id,
                'prize_key' => $this->prize->prize_key,
                'name' => $this->prize->name,
                'type' => $this->prize->type->value,
                'icon' => $this->prize->icon ?? $this->prize->type->icon(),
            ]),
            'total_limit' => $this->total_limit,
            'remaining' => $this->remaining,
            'remaining_percentage' => $this->getRemainingPercentage(),
            'daily_limit' => $this->daily_limit,
            'daily_remaining' => $this->daily_remaining,
            'reset_daily_at' => $this->reset_daily_at?->toISOString(),
            'has_stock' => $this->hasStock(),
            'needs_daily_reset' => $this->needsDailyReset(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
