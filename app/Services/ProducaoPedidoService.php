<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CapaPersonalizadaStatus;
use App\Enums\ProducaoPedidoStatus;
use App\Models\ProducaoPedido;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProducaoPedidoService
{
    public function __construct(
        private readonly ProducaoEventoService $eventoService
    ) {
    }

    /**
     * List production orders with filters.
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = ProducaoPedido::with(['createdBy'])
            ->byStatus($filters['status'] ?? null)
            ->createdBetween($filters['initial_date'] ?? null, $filters['final_date'] ?? null);

        // Exclude cart if not admin
        $user = Auth::user();
        if ($user && $user->hasRole('fabrica')) {
            $query->visibleToFactory();
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get production order details with items and timeline.
     */
    public function getDetails(int $pedidoId): ProducaoPedido
    {
        return ProducaoPedido::with([
            'itens.capaPersonalizada.customer',
            'itens.capaPersonalizada.customerDevice.phoneModel.brand',
            'createdBy',
            'eventos',
        ])->findOrFail($pedidoId);
    }

    /**
     * Factory accepts the order and sets total price.
     */
    public function accept(ProducaoPedido $pedido, float $factoryTotal, ?string $notes = null): ProducaoPedido
    {
        if (!$pedido->canAccept()) {
            throw ValidationException::withMessages([
                'status' => ['Este pedido não pode ser aceito no status atual.'],
            ]);
        }

        return DB::transaction(function () use ($pedido, $factoryTotal, $notes) {
            $user = Auth::user();

            $pedido->update([
                'status' => ProducaoPedidoStatus::PEDIDO_ACEITO,
                'factory_total' => $factoryTotal,
                'factory_notes' => $notes,
                'accepted_at' => now(),
            ]);

            $this->eventoService->logPedidoAceito($pedido->id, $factoryTotal, $user);

            return $pedido->fresh();
        });
    }

    /**
     * Factory dispatches the order.
     */
    public function dispatch(ProducaoPedido $pedido, ?string $trackingCode = null, ?string $notes = null): ProducaoPedido
    {
        if (!$pedido->canDispatch()) {
            throw ValidationException::withMessages([
                'status' => ['Este pedido não pode ser despachado no status atual.'],
            ]);
        }

        return DB::transaction(function () use ($pedido, $trackingCode, $notes) {
            $user = Auth::user();

            $updateData = [
                'status' => ProducaoPedidoStatus::PEDIDO_DESPACHADO,
                'dispatched_at' => now(),
            ];

            if ($notes) {
                $updateData['factory_notes'] = $pedido->factory_notes
                    ? $pedido->factory_notes . "\n\n" . $notes
                    : $notes;
            }

            $pedido->update($updateData);

            $this->eventoService->logPedidoDespachado($pedido->id, $trackingCode, $user);

            return $pedido->fresh();
        });
    }

    /**
     * Admin receives the order and distributes items.
     */
    public function receive(ProducaoPedido $pedido, ?string $observation = null): ProducaoPedido
    {
        if (!$pedido->canReceive()) {
            throw ValidationException::withMessages([
                'status' => ['Este pedido não pode ser recebido no status atual.'],
            ]);
        }

        return DB::transaction(function () use ($pedido, $observation) {
            $user = Auth::user();

            $pedido->update([
                'status' => ProducaoPedidoStatus::RECEBIDO,
                'received_at' => now(),
            ]);

            // Update all capas to DISPONIVEL_LOJA
            foreach ($pedido->itens as $item) {
                $capa = $item->capaPersonalizada;
                if ($capa) {
                    $capa->update([
                        'status' => CapaPersonalizadaStatus::DISPONIVEL_LOJA,
                    ]);
                }
            }

            $this->eventoService->logPedidoRecebido($pedido->id, $observation, $user);

            return $pedido->fresh();
        });
    }

    /**
     * Cancel an order (admin only, before dispatch).
     */
    public function cancel(ProducaoPedido $pedido, ?string $reason = null): ProducaoPedido
    {
        if (!$pedido->canCancel()) {
            throw ValidationException::withMessages([
                'status' => ['Este pedido não pode ser cancelado no status atual.'],
            ]);
        }

        return DB::transaction(function () use ($pedido, $reason) {
            $user = Auth::user();

            $pedido->update([
                'status' => ProducaoPedidoStatus::CANCELADO,
            ]);

            // Revert all capas to ENCOMENDA_SOLICITADA
            foreach ($pedido->itens as $item) {
                $capa = $item->capaPersonalizada;
                if ($capa) {
                    $capa->update([
                        'status' => CapaPersonalizadaStatus::ENCOMENDA_SOLICITADA,
                        'sended_to_production_at' => null,
                        'producao_pedido_id' => null,
                    ]);
                }
            }

            $this->eventoService->log(
                'producao_pedido',
                $pedido->id,
                'pedido_cancelado',
                $pedido->status->value,
                ProducaoPedidoStatus::CANCELADO->value,
                $reason ? ['reason' => $reason] : null,
                $user
            );

            return $pedido->fresh();
        });
    }
}
