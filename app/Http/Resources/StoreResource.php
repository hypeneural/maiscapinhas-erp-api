<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'codigo' => $this->codigo,
            'city' => $this->city,
            'active' => $this->active,
            'troco_padrao' => $this->troco_padrao ? (float) $this->troco_padrao : null,

            // Image
            'photo_url' => $this->photo_url,

            // Address
            'address' => $this->address,
            'neighborhood' => $this->neighborhood,
            'state' => $this->state,
            'zip_code' => $this->zip_code,
            'full_address' => $this->getFullAddress(),

            // GPS
            'latitude' => $this->latitude ? (float) $this->latitude : null,
            'longitude' => $this->longitude ? (float) $this->longitude : null,

            // Contact
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'instagram' => $this->instagram,

            // Hours
            'opening_hours' => $this->opening_hours,

            // Business info
            'cnpj' => $this->cnpj,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'users_count' => $this->whenCounted('storeUsers'),
            'users' => $this->whenLoaded('storeUsers', function () {
                return $this->storeUsers->map(fn($su) => [
                    'user_id' => $su->user_id,
                    'user_name' => $su->user?->name,
                    'user_email' => $su->user?->email,
                    'role' => $su->role,
                ]);
            }),
        ];
    }

    /**
     * Formata endereço completo.
     */
    private function getFullAddress(): ?string
    {
        $parts = array_filter([
            $this->address,
            $this->neighborhood,
            $this->city,
            $this->state,
            $this->zip_code,
        ]);

        return count($parts) > 0 ? implode(', ', $parts) : null;
    }
}
