<?php

declare(strict_types=1);

namespace App\Http\Resources\Wheel;

use App\Models\WheelCampaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_key' => $this->campaign_key,
            'name' => $this->name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'status_icon' => $this->status->icon(),
            'can_activate' => $this->status->canActivate(),
            'can_pause' => $this->status->canPause(),
            'can_end' => $this->status->canEnd(),
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'is_within_period' => $this->isWithinPeriod(),
            'terms_version' => $this->terms_version,
            'settings' => array_merge(
                WheelCampaign::DEFAULT_SETTINGS,
                is_string($this->settings) ? json_decode($this->settings, true) ?? [] : ($this->settings ?? [])
            ),
            'screens_count' => $this->whenCounted('screens'),
            'active_segments_count' => $this->whenCounted('activeSegments'),
            'total_weight' => $this->whenLoaded(
                'activeSegments',
                fn() =>
                $this->activeSegments->sum('probability_weight')
            ),
            'segments' => SegmentResource::collection($this->whenLoaded('activeSegments')),
            'inventory' => InventoryResource::collection($this->whenLoaded('inventory')),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
