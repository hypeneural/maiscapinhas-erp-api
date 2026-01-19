<?php

declare(strict_types=1);

namespace App\Http\Resources\Wheel;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource: PlayerResource
 * 
 * Representação de um jogador para a API admin.
 */
class PlayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'player_key' => $this->player_key,

            // Dados pessoais
            'full_name' => $this->full_name,
            'phone_masked' => $this->phone_masked,

            // WhatsApp
            'whatsapp_confirmed' => $this->whatsapp_confirmed_at !== null,
            'whatsapp_confirmed_at' => $this->whatsapp_confirmed_at?->toISOString(),
            'whatsapp_lid' => $this->whenNotNull($this->whatsapp_lid),

            // Endereço
            'address' => [
                'cep' => $this->cep,
                'street' => $this->street,
                'number' => $this->number,
                'complement' => $this->complement,
                'neighborhood' => $this->neighborhood,
                'city' => $this->city,
                'state' => $this->state,
                'ibge' => $this->ibge,
                'full' => $this->getFullAddress(),
            ],
            'has_address' => $this->hasCompleteAddress(),

            // Contadores (se carregados)
            'sessions_count' => $this->whenCounted('sessionPlayers'),
            'spins_count' => $this->when(isset($this->spins_count), $this->spins_count),

            // Última participação
            'last_session' => $this->when(
                $this->relationLoaded('sessionPlayers') && $this->sessionPlayers->isNotEmpty(),
                function () {
                    $lastSp = $this->sessionPlayers->first();
                    return [
                        'session_key' => $lastSp->session?->session_key,
                        'store' => $lastSp->session?->screen?->store?->name,
                        'campaign' => $lastSp->session?->campaign?->name,
                        'joined_at' => $lastSp->joined_at?->toISOString(),
                    ];
                }
            ),

            // Atividade
            'last_seen_at' => $this->last_seen_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Endereço completo formatado.
     */
    private function getFullAddress(): ?string
    {
        if (!$this->resource->street) {
            return null;
        }

        $parts = array_filter([
            $this->resource->street,
            $this->resource->number,
            $this->resource->complement,
            $this->resource->neighborhood,
            $this->resource->city,
            $this->resource->state,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Verifica se tem endereço completo.
     */
    private function hasCompleteAddress(): bool
    {
        return !empty($this->resource->cep) &&
            !empty($this->resource->city) &&
            !empty($this->resource->state);
    }
}
