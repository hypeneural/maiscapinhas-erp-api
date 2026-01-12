<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnouncementSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $receipt = $this->receipts->first();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'type' => [
                'value' => $this->type->value,
                'label' => $this->type->label(),
            ],
            'severity' => [
                'value' => $this->severity->value,
                'label' => $this->severity->label(),
                'color' => $this->severity->color(),
            ],
            'display_mode' => [
                'value' => $this->display_mode->value,
                'label' => $this->display_mode->label(),
            ],
            'require_ack' => $this->require_ack,
            'icon' => $this->icon,
            'image_url' => $this->image_url,
            'image_alt' => $this->image_alt,
            'cta_label' => $this->cta_label,
            'cta_url' => $this->cta_url,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_pinned' => $this->isPinned(),
            'is_critical' => $this->isCritical(),
            'receipt' => $receipt ? new AnnouncementReceiptResource($receipt) : null,
        ];
    }
}
