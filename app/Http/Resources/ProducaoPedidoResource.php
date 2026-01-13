<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProducaoPedidoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'status_icon' => $this->status->icon(),
            'total_itens' => $this->total_itens,
            'total_qtd' => $this->total_qtd,
            'factory_total' => $this->factory_total ? (float) $this->factory_total : null,
            'factory_notes' => $this->factory_notes,
            'observation' => $this->observation,
            'created_at' => $this->created_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'created_by' => $this->when($this->relationLoaded('createdBy'), function () {
                return $this->createdBy ? [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ] : null;
            }),
            'items' => ProducaoPedidoItemResource::collection($this->whenLoaded('itens')),
            'timeline' => ProducaoEventoResource::collection($this->whenLoaded('eventos')),

            // Action flags
            'can_accept' => $this->canAccept(),
            'can_dispatch' => $this->canDispatch(),
            'can_receive' => $this->canReceive(),
            'can_cancel' => $this->canCancel(),
            'is_carrinho_aberto' => $this->isCarrinhoAberto(),
        ];
    }
}
