<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,

            // Address
            'zip_code' => $this->zip_code,
            'street' => $this->street,
            'number' => $this->number,
            'complement' => $this->complement,
            'neighborhood' => $this->neighborhood,
            'city' => $this->city,
            'state' => $this->state,

            // Profile
            'birth_date' => $this->birth_date?->format('Y-m-d'),

            // Relationships
            'devices' => CustomerDeviceResource::collection($this->whenLoaded('devices')),
            'created_by' => $this->when(
                $this->relationLoaded('createdBy'),
                fn() => [
                    'id' => $this->createdBy?->id,
                    'name' => $this->createdBy?->name,
                ]
            ),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
