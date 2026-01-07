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
            'city' => $this->city,
            'active' => $this->active,
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
}
