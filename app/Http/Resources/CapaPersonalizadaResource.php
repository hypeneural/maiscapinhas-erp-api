<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CapaPersonalizadaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'selected_product' => $this->selected_product,
            'product_reference' => $this->product_reference,
            'obs' => $this->obs,
            'photo_path' => $this->photo_path,
            'photo_url' => $this->photo_url,

            // Quantity and Price
            'qty' => $this->qty,
            'price' => $this->price,
            'total' => $this->total,

            // Payment
            'payed' => $this->payed,
            'payday' => $this->payday?->format('Y-m-d'),
            'received_by' => $this->when(
                $this->relationLoaded('receivedBy'),
                fn() => [
                    'id' => $this->receivedBy?->id,
                    'name' => $this->receivedBy?->name,
                ]
            ),
            'received_by_id' => $this->when(!$this->relationLoaded('receivedBy'), $this->received_by_id),

            // Production
            'sended_to_production_at' => $this->sended_to_production_at?->format('Y-m-d'),

            // Status
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

            // Production Order (if linked)
            'producao_pedido_id' => $this->producao_pedido_id,
            'producao_history' => $this->getProducaoHistory(),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
