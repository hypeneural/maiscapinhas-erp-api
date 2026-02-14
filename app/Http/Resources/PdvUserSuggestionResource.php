<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PdvUserSuggestionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // $this is likely an object or array from the custom query
        $resource = (object) $this->resource;

        return [
            'identity' => [
                'store_pdv_id' => $resource->store_pdv_id,
                'user_pdv_id' => $resource->vendedor_pdv_id,
                'original_name' => $resource->pdv_user_name ?? null,
                'original_login' => $resource->pdv_user_login ?? null,
            ],
            'last_seen_at' => $resource->last_seen_at ?? null,
            'sales_count' => $resource->sales_count ?? 0,
            'suggestion' => isset($resource->suggested_user) ? [
                'user_id' => $resource->suggested_user->id,
                'name' => $resource->suggested_user->name,
                'confidence' => $resource->match_confidence ?? 0,
            ] : null,
        ];
    }
}
