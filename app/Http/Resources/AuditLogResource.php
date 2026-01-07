<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource para formatação de logs de auditoria.
 * 
 * Retorna dados filtrados e formatados, sem expor informações sensíveis.
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'action' => $this->action,
            'log_name' => $this->log_name,
            'created_at' => $this->created_at?->toIso8601String(),

            'causer' => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ] : null,

            'subject' => $this->entity_type ? [
                'type' => $this->entity_type,
                'id' => $this->entity_id,
            ] : null,

            'store' => $this->store_id ? [
                'id' => $this->store_id,
                'name' => $this->whenLoaded('store', fn() => $this->store->name),
            ] : null,

            'context' => [
                'request_id' => $this->request_id,
                'ip' => $this->ip,
                // Truncar user_agent para não poluir response
                'user_agent' => $this->user_agent
                    ? (strlen($this->user_agent) > 100
                        ? substr($this->user_agent, 0, 100) . '...'
                        : $this->user_agent)
                    : null,
            ],

            // Properties (limitado para não retornar payloads gigantes)
            'properties' => $this->getFormattedProperties(),
        ];
    }

    /**
     * Formata properties limitando tamanho.
     */
    private function getFormattedProperties(): ?array
    {
        $after = $this->after_json;

        if (!$after) {
            return null;
        }

        // Se for muito grande, resumir
        $json = json_encode($after);
        if (strlen($json) > 5000) {
            return [
                '_truncated' => true,
                '_size' => strlen($json),
                '_message' => 'Properties truncadas. Consulte detalhes via ID.',
            ];
        }

        return $after;
    }
}
