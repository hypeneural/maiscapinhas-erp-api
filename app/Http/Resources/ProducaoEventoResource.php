<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ProducaoPedidoStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProducaoEventoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'action_label' => $this->action_label,
            'action_icon' => $this->action_icon,
            'from_status' => $this->from_status,
            'from_status_label' => $this->from_status
                ? ProducaoPedidoStatus::tryFrom($this->from_status)?->label()
                : null,
            'to_status' => $this->to_status,
            'to_status_label' => $this->to_status
                ? ProducaoPedidoStatus::tryFrom($this->to_status)?->label()
                : null,
            'metadata' => $this->metadata,
            'actor_type' => $this->actor_type,
            'actor_name' => $this->actor_name,
            'created_at' => $this->created_at?->toIso8601String(),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
