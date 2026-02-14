<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PdvStoreMappingResource extends JsonResource
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
            'pdv_store_id' => $this->pdv_store_id,
            'alias' => $this->alias,
            'cnpj' => $this->cnpj,
            'active' => $this->active,
            'store' => $this->store ? [
                'id' => $this->store->id,
                'name' => $this->store->name,
                'cnpj' => $this->store->cnpj,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
