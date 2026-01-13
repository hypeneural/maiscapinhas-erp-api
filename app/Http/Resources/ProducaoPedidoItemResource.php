<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProducaoPedidoItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'capa_id' => $this->capa_personalizada_id,
            'phone_brand' => $this->phone_brand,
            'phone_model' => $this->phone_model,
            'qty' => $this->qty,
            'observation' => $this->observation,
            'photo_url' => $this->photo_url,
            'photo_download_url' => $this->when(
                $request->user()?->hasRole('fabrica') || $request->user()?->isGlobalAdmin(),
                fn() => route('api.v1.fabrica.pedidos.item.foto', [
                    'pedido' => $this->producao_pedido_id,
                    'item' => $this->id,
                ])
            ),
            'customer' => $this->when($this->relationLoaded('capaPersonalizada'), function () {
                $capa = $this->capaPersonalizada;
                return $capa?->customer ? [
                    'id' => $capa->customer->id,
                    'name' => $capa->customer->name,
                ] : null;
            }),
            'selected_product' => $this->when($this->relationLoaded('capaPersonalizada'), function () {
                return $this->capaPersonalizada?->selected_product;
            }),
            'added_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
