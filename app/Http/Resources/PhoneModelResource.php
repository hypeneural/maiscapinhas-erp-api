<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PhoneModelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'marketing_name' => $this->marketing_name,
            'release_year' => $this->release_year,
            'form_factor' => $this->form_factor?->value,
            'form_factor_label' => $this->form_factor?->label(),
            'full_name' => $this->full_name,

            // Brand
            'brand' => $this->when(
                $this->relationLoaded('brand'),
                fn() => [
                    'id' => $this->brand?->id,
                    'brand_name' => $this->brand?->brand_name,
                    'brand_slug' => $this->brand?->brand_slug,
                ]
            ),
            'brand_id' => $this->when(!$this->relationLoaded('brand'), $this->brand_id),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
