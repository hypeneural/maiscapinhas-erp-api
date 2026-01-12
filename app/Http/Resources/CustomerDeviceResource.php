<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'nickname' => $this->nickname,
            'is_primary' => $this->is_primary,
            'display_name' => $this->display_name,

            // Phone Model with Brand
            'phone_model' => $this->when(
                $this->relationLoaded('phoneModel'),
                fn() => [
                    'id' => $this->phoneModel?->id,
                    'marketing_name' => $this->phoneModel?->marketing_name,
                    'release_year' => $this->phoneModel?->release_year,
                    'form_factor' => $this->phoneModel?->form_factor?->value,
                    'full_name' => $this->phoneModel?->full_name,
                    'brand' => $this->when(
                        $this->phoneModel?->relationLoaded('brand'),
                        fn() => [
                            'id' => $this->phoneModel?->brand?->id,
                            'brand_name' => $this->phoneModel?->brand?->brand_name,
                        ]
                    ),
                ]
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
