<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'seller_id' => $this->seller_id,
            'sold_at' => $this->sold_at?->toIso8601String(),
            'amount' => (float) $this->amount,
            'source' => $this->source,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'store' => $this->whenLoaded('store', fn() => [
                'id' => $this->store->id,
                'name' => $this->store->name,
            ]),
            'seller' => $this->whenLoaded('seller', fn() => [
                'id' => $this->seller->id,
                'name' => $this->seller->name,
            ]),
        ];
    }
}
