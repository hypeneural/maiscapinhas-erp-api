<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PedidoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'selected_product' => $this->selected_product,
            'obs' => $this->obs,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'status_color' => $this->status?->color(),

            // Store
            'store' => $this->when(
                $this->relationLoaded('store'),
                fn() => [
                    'id' => $this->store?->id,
                    'name' => $this->store?->name,
                    'city' => $this->store?->city,
                ]
            ),
            'store_id' => $this->when(!$this->relationLoaded('store'), $this->store_id),

            // User (vendedor)
            'user' => $this->when(
                $this->relationLoaded('user'),
                fn() => [
                    'id' => $this->user?->id,
                    'name' => $this->user?->name,
                ]
            ),
            'user_id' => $this->when(!$this->relationLoaded('user'), $this->user_id),

            // Customer
            'customer' => $this->when(
                $this->relationLoaded('customer'),
                fn() => [
                    'id' => $this->customer?->id,
                    'name' => $this->customer?->name,
                    'email' => $this->customer?->email,
                    'phone' => $this->customer?->phone,
                ]
            ),
            'customer_id' => $this->when(!$this->relationLoaded('customer'), $this->customer_id),

            // Customer Device
            'customer_device' => $this->when(
                $this->relationLoaded('customerDevice') && $this->customerDevice,
                fn() => new CustomerDeviceResource($this->customerDevice)
            ),
            'customer_device_id' => $this->customer_device_id,

            // Audit
            'created_by' => $this->when(
                $this->relationLoaded('createdBy'),
                fn() => [
                    'id' => $this->createdBy?->id,
                    'name' => $this->createdBy?->name,
                ]
            ),
            'updated_by' => $this->when(
                $this->relationLoaded('updatedBy'),
                fn() => [
                    'id' => $this->updatedBy?->id,
                    'name' => $this->updatedBy?->name,
                ]
            ),

            // Status History
            'status_history' => $this->when(
                $this->relationLoaded('statusHistory'),
                fn() => $this->statusHistory->map(fn($h) => [
                    'id' => $h->id,
                    'old_status' => $h->old_status?->value,
                    'old_status_label' => $h->old_status?->label(),
                    'new_status' => $h->new_status?->value,
                    'new_status_label' => $h->new_status?->label(),
                    'changed_by' => [
                        'id' => $h->changedBy?->id,
                        'name' => $h->changedBy?->name,
                    ],
                    'changed_at' => $h->changed_at?->toIso8601String(),
                    'source' => $h->source,
                    'reason' => $h->reason,
                ])
            ),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
