<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnouncementDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $receipt = $this->receipts->first();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'message' => $this->message,
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
            'scope' => [
                'value' => $this->scope->value,
                'label' => $this->scope->label(),
            ],
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'require_ack' => $this->require_ack,
            'icon' => $this->icon,
            'image_url' => $this->image_url,
            'image_alt' => $this->image_alt,
            'cta_label' => $this->cta_label,
            'cta_url' => $this->cta_url,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'repeat_every_minutes' => $this->repeat_every_minutes,
            'priority' => $this->priority,
            'pinned_until' => $this->pinned_until?->toIso8601String(),
            'is_pinned' => $this->isPinned(),
            'is_critical' => $this->isCritical(),
            'meta_json' => $this->meta_json,

            // Targets
            'targets' => $this->targets->map(fn($t) => [
                'target_type' => $t->target_type->value,
                'target_id' => $t->target_id,
            ]),

            // Publishing info
            'published_at' => $this->published_at?->toIso8601String(),
            'published_by' => $this->publishedBy ? [
                'id' => $this->publishedBy->id,
                'name' => $this->publishedBy->name,
            ] : null,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'archived_by' => $this->archivedBy ? [
                'id' => $this->archivedBy->id,
                'name' => $this->archivedBy->name,
            ] : null,

            // Creator
            'created_by' => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ],
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),

            // Current user receipt
            'receipt' => $receipt ? new AnnouncementReceiptResource($receipt) : null,
        ];
    }
}
