<?php

declare(strict_types=1);

namespace App\Http\Resources\Wheel;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScreenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'screen_key' => $this->screen_key,
            'name' => $this->name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'store' => $this->whenLoaded('store', fn() => [
                'id' => $this->store->id,
                'name' => $this->store->name,
                'city' => $this->store->city,
            ]),
            'store_id' => $this->store_id,
            'device_info' => $this->device_info,
            'is_online' => $this->isOnline(),
            'last_seen_at' => $this->last_seen_at?->toISOString(),
            'last_seen_ago' => $this->last_seen_at?->diffForHumans(),
            'active_campaign' => $this->whenLoaded('activeCampaigns', function () {
                $campaign = $this->activeCampaigns->first();
                if (!$campaign) {
                    return null;
                }
                return [
                    'id' => $campaign->id,
                    'campaign_key' => $campaign->campaign_key,
                    'name' => $campaign->name,
                    'status' => $campaign->status->value,
                ];
            }),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
