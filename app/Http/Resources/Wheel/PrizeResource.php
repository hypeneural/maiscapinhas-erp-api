<?php

declare(strict_types=1);

namespace App\Http\Resources\Wheel;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrizeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prize_key' => $this->prize_key,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'type_icon' => $this->type->icon(),
            'type_color' => $this->type->color(),
            'icon' => $this->icon,
            'description' => $this->description,
            'redeem_instructions' => $this->redeem_instructions,
            'code_prefix' => $this->code_prefix,
            'requires_redeem' => $this->requiresRedeem(),
            'consumes_inventory' => $this->consumesInventory(),
            'active' => $this->active,
            'segments_count' => $this->whenCounted('segments'),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
