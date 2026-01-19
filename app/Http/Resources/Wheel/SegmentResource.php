<?php

declare(strict_types=1);

namespace App\Http\Resources\Wheel;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SegmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'segment_key' => $this->segment_key,
            'label' => $this->label,
            'color' => $this->color,
            'prize_id' => $this->prize_id,
            'prize' => $this->whenLoaded('prize', fn() => [
                'id' => $this->prize->id,
                'prize_key' => $this->prize->prize_key,
                'name' => $this->prize->name,
                'type' => $this->prize->type->value,
                'icon' => $this->prize->icon ?? $this->prize->type->icon(),
                'active' => $this->prize->active,
            ]),
            'probability_weight' => $this->probability_weight,
            'probability_percentage' => $this->getProbabilityPercentage(),
            'sort_order' => $this->sort_order,
            'active' => $this->active,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
