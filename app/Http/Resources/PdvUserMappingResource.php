<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PdvUserMappingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pdv_identity' => [
                'store_pdv_id' => $this->store_pdv_id,
                'user_pdv_id' => $this->pdv_user_id,
                'original_name' => $this->pdv_user_name,
                'original_login' => $this->pdv_user_login,
            ],
            'mapped_to' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar_url' => $this->user->avatar_url,
            ] : null,
            'store_mapping' => $this->storeMapping ? [
                'store_id' => $this->storeMapping->store_id,
                'alias' => $this->storeMapping->alias,
            ] : null,
            'is_store_operator' => $this->is_store_operator,
            'active' => $this->active,
            'confidence' => $this->confidence,
            'source' => $this->source,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
