<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnouncementReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'seen_at' => $this->seen_at?->toIso8601String(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'dismissed_at' => $this->dismissed_at?->toIso8601String(),
            'last_shown_at' => $this->last_shown_at?->toIso8601String(),
            'show_count' => $this->show_count,
        ];
    }
}
