<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\OpeningHoursService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource for Bio endpoint - public-facing store information.
 * 
 * Excludes sensitive business data like CNPJ, troco_padrao, etc.
 * Includes calculated hours_human for opening hours display.
 */
class BioStoreResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $openingHoursService = app(OpeningHoursService::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'city' => $this->city,

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
            'hours_human' => $openingHoursService->calculate($this->opening_hours),
        ];
    }

    /**
     * Format full address.
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
