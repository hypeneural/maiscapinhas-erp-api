<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsAppInstanceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'user_id' => $this->user_id,
            'scope' => $this->scope,
            'provider' => $this->provider,
            'name' => $this->name,
            'phone_e164' => $this->phone_e164,
            'base_url' => $this->base_url,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'status' => $this->status,
            'last_state' => $this->last_state,
            'last_state_checked_at' => $this->last_state_checked_at?->toIso8601String(),
            'webhook_url' => $this->webhook_url,
            'webhook_events' => $this->webhook_events,
            'has_api_key' => $this->has_api_key,
            'has_token' => $this->has_token,
            'api_key_masked' => $this->api_key_masked,
            'token_masked' => $this->token_masked,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),

            // Related resources (when loaded)
            'store' => $this->whenLoaded('store', fn() => [
                'id' => $this->store->id,
                'name' => $this->store->name,
            ]),
            'user' => $this->whenLoaded('user', fn() => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
        ];
    }
}
