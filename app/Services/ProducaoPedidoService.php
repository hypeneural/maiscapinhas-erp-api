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
     * Syncs all capas to EM_PRODUCAO status.
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

            // Sync all capas to EM_PRODUCAO
            foreach ($pedido->itens as $item) {
                $capa = $item->capaPersonalizada;
                if ($capa) {
                    $capa->update([
                        'status' => CapaPersonalizadaStatus::EM_PRODUCAO,
                    ]);
                }
            }

            $this->eventoService->logPedidoAceito($pedido->id, $factoryTotal, $user);

            return $pedido->fresh();
        });
    }

    /**
     * Factory dispatches the order.
     * Syncs all capas to DESPACHADO status.
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

            // Sync all capas to DESPACHADO
            foreach ($pedido->itens as $item) {
                $capa = $item->capaPersonalizada;
                if ($capa) {
                    $capa->update([
                        'status' => CapaPersonalizadaStatus::DESPACHADO,
                    ]);
                }
            }

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

    /**
     * Factory rejects individual items with justification.
     * Each rejected capa is set to RECUSADA_FABRICA status.
     *
     * @param array<int, string> $rejections Map of item_id => reason
     */
    public function rejectItems(ProducaoPedido $pedido, array $rejections): ProducaoPedido
    {
        if (
            !in_array($pedido->status, [
                ProducaoPedidoStatus::ENCOMENDA_REALIZADA,
                ProducaoPedidoStatus::PEDIDO_ACEITO,
            ])
        ) {
            throw ValidationException::withMessages([
                'status' => ['Itens não podem ser recusados no status atual.'],
            ]);
        }

        return DB::transaction(function () use ($pedido, $rejections) {
            $user = Auth::user();
            $rejectedCount = 0;

            foreach ($rejections as $itemId => $reason) {
                $item = $pedido->itens()->find($itemId);
                if (!$item)
                    continue;

                $capa = $item->capaPersonalizada;
                if ($capa) {
                    $capa->update([
                        'status' => CapaPersonalizadaStatus::RECUSADA_FABRICA,
                        'sended_to_production_at' => null,
                        'producao_pedido_id' => null,
                    ]);

                    // Log rejection event
                    $this->eventoService->log(
                        'capa_personalizada',
                        $capa->id,
                        'item_recusado',
                        $capa->status->value,
                        CapaPersonalizadaStatus::RECUSADA_FABRICA->value,
                        ['reason' => $reason, 'producao_pedido_id' => $pedido->id],
                        $user
                    );

                    $rejectedCount++;
                }

                // Remove item from pedido
                $item->delete();
            }

            // Recalculate totals
            $pedido->recalculateTotals();

            // Log event
            $this->eventoService->log(
                'producao_pedido',
                $pedido->id,
                'itens_recusados',
                $pedido->status->value,
                $pedido->status->value,
                ['rejected_count' => $rejectedCount, 'item_ids' => array_keys($rejections)],
                $user
            );

            return $pedido->fresh(['itens']);
        });
    }
}

