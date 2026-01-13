<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProducaoPedidoStatus;
use App\Models\ProducaoEvento;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class ProducaoEventoService
{
    /**
     * Log an event for a producao pedido or capa personalizada.
     */
    public function log(
        string $entityType,
        int $entityId,
        string $action,
        ?int $fromStatus = null,
        ?int $toStatus = null,
        ?array $metadata = null,
        ?User $actor = null
    ): ProducaoEvento {
        $actor = $actor ?? Auth::user();

        return ProducaoEvento::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'metadata' => $metadata,
            'actor_id' => $actor?->id ?? 0,
            'actor_type' => $this->getActorType($actor),
            'actor_name' => $actor?->name ?? 'Sistema',
            'created_at' => now(),
        ]);
    }

    /**
     * Log a cart created event.
     */
    public function logCarrinhoCriado(int $pedidoId, ?User $actor = null): ProducaoEvento
    {
        return $this->log(
            ProducaoEvento::ENTITY_PRODUCAO_PEDIDO,
            $pedidoId,
            ProducaoEvento::ACTION_CARRINHO_CRIADO,
            null,
            ProducaoPedidoStatus::CARRINHO_ABERTO->value,
            null,
            $actor
        );
    }

    /**
     * Log an item added to cart event.
     */
    public function logItemAdicionado(
        int $pedidoId,
        int $capaId,
        string $phoneModel,
        ?User $actor = null
    ): ProducaoEvento {
        return $this->log(
            ProducaoEvento::ENTITY_PRODUCAO_PEDIDO,
            $pedidoId,
            ProducaoEvento::ACTION_ITEM_ADICIONADO,
            null,
            null,
            ['capa_id' => $capaId, 'phone_model' => $phoneModel],
            $actor
        );
    }

    /**
     * Log an item removed from cart event.
     */
    public function logItemRemovido(int $pedidoId, int $capaId, ?User $actor = null): ProducaoEvento
    {
        return $this->log(
            ProducaoEvento::ENTITY_PRODUCAO_PEDIDO,
            $pedidoId,
            ProducaoEvento::ACTION_ITEM_REMOVIDO,
            null,
            null,
            ['capa_id' => $capaId],
            $actor
        );
    }

    /**
     * Log a cart closed event.
     */
    public function logCarrinhoFechado(
        int $pedidoId,
        int $totalItens,
        int $totalQtd,
        ?User $actor = null
    ): ProducaoEvento {
        return $this->log(
            ProducaoEvento::ENTITY_PRODUCAO_PEDIDO,
            $pedidoId,
            ProducaoEvento::ACTION_CARRINHO_FECHADO,
            ProducaoPedidoStatus::CARRINHO_ABERTO->value,
            ProducaoPedidoStatus::ENCOMENDA_REALIZADA->value,
            ['total_itens' => $totalItens, 'total_qtd' => $totalQtd],
            $actor
        );
    }

    /**
     * Log a pedido accepted event.
     */
    public function logPedidoAceito(
        int $pedidoId,
        float $factoryTotal,
        ?User $actor = null
    ): ProducaoEvento {
        return $this->log(
            ProducaoEvento::ENTITY_PRODUCAO_PEDIDO,
            $pedidoId,
            ProducaoEvento::ACTION_PEDIDO_ACEITO,
            ProducaoPedidoStatus::ENCOMENDA_REALIZADA->value,
            ProducaoPedidoStatus::PEDIDO_ACEITO->value,
            ['factory_total' => $factoryTotal],
            $actor
        );
    }

    /**
     * Log a pedido dispatched event.
     */
    public function logPedidoDespachado(
        int $pedidoId,
        ?string $trackingCode = null,
        ?User $actor = null
    ): ProducaoEvento {
        return $this->log(
            ProducaoEvento::ENTITY_PRODUCAO_PEDIDO,
            $pedidoId,
            ProducaoEvento::ACTION_PEDIDO_DESPACHADO,
            ProducaoPedidoStatus::PEDIDO_ACEITO->value,
            ProducaoPedidoStatus::PEDIDO_DESPACHADO->value,
            $trackingCode ? ['tracking_code' => $trackingCode] : null,
            $actor
        );
    }

    /**
     * Log a pedido received event.
     */
    public function logPedidoRecebido(
        int $pedidoId,
        ?string $observation = null,
        ?User $actor = null
    ): ProducaoEvento {
        return $this->log(
            ProducaoEvento::ENTITY_PRODUCAO_PEDIDO,
            $pedidoId,
            ProducaoEvento::ACTION_PEDIDO_RECEBIDO,
            ProducaoPedidoStatus::PEDIDO_DESPACHADO->value,
            ProducaoPedidoStatus::RECEBIDO->value,
            $observation ? ['observation' => $observation] : null,
            $actor
        );
    }

    /**
     * Log a capa added to cart event.
     */
    public function logCapaAdicionadaCarrinho(
        int $capaId,
        int $pedidoId,
        ?User $actor = null
    ): ProducaoEvento {
        return $this->log(
            ProducaoEvento::ENTITY_CAPA_PERSONALIZADA,
            $capaId,
            'adicionada_carrinho',
            null,
            null,
            ['producao_pedido_id' => $pedidoId],
            $actor
        );
    }

    /**
     * Log a capa sent to factory event.
     */
    public function logCapaEnviadaFabrica(
        int $capaId,
        int $pedidoId,
        int $fromStatus,
        int $toStatus,
        ?User $actor = null
    ): ProducaoEvento {
        return $this->log(
            ProducaoEvento::ENTITY_CAPA_PERSONALIZADA,
            $capaId,
            'enviada_fabrica',
            $fromStatus,
            $toStatus,
            ['producao_pedido_id' => $pedidoId],
            $actor
        );
    }

    /**
     * Determine actor type based on user roles.
     */
    private function getActorType(?User $user): string
    {
        if (!$user) {
            return ProducaoEvento::ACTOR_SISTEMA;
        }

        if ($user->hasRole('fabrica')) {
            return ProducaoEvento::ACTOR_FABRICA;
        }

        if ($user->hasRole('admin') || $user->hasRole('super_admin')) {
            return ProducaoEvento::ACTOR_ADMIN;
        }

        return ProducaoEvento::ACTOR_VENDEDOR;
    }
}
