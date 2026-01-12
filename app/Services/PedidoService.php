<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PedidoStatus;
use App\Models\Pedido;
use App\Models\PedidoStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PedidoService
{
    /**
     * Record status change in history.
     */
    public function recordStatusChange(
        Pedido $pedido,
        ?PedidoStatus $oldStatus,
        PedidoStatus $newStatus,
        User $changedBy,
        string $source = 'api',
        ?string $reason = null,
        ?array $meta = null
    ): PedidoStatusHistory {
        return PedidoStatusHistory::create([
            'pedido_id' => $pedido->id,
            'old_status' => $oldStatus?->value,
            'new_status' => $newStatus->value,
            'changed_by_id' => $changedBy->id,
            'changed_at' => now(),
            'source' => $source,
            'reason' => $reason,
            'meta_json' => $meta,
        ]);
    }

    /**
     * Create a new pedido with initial status history.
     */
    public function createPedido(array $data, User $user): Pedido
    {
        return DB::transaction(function () use ($data, $user) {
            // Set default values
            $data['user_id'] = $data['user_id'] ?? $user->id;
            $data['created_by_id'] = $user->id;

            // Get status from data or default
            $status = isset($data['status'])
                ? PedidoStatus::from($data['status'])
                : PedidoStatus::SOLICITADO;
            $data['status'] = $status->value;

            $pedido = Pedido::create($data);

            // Record initial status
            $this->recordStatusChange(
                $pedido,
                null,
                $status,
                $user,
                'api',
                'Pedido criado'
            );

            return $pedido;
        });
    }

    /**
     * Update pedido status with history.
     */
    public function updateStatus(
        Pedido $pedido,
        int $newStatusValue,
        User $changedBy,
        ?string $reason = null,
        string $source = 'api'
    ): Pedido {
        $oldStatus = $pedido->status;
        $newStatus = PedidoStatus::from($newStatusValue);

        // Only record if status actually changed
        if ($oldStatus->value !== $newStatus->value) {
            $pedido->update([
                'status' => $newStatus->value,
                'updated_by_id' => $changedBy->id,
            ]);

            $this->recordStatusChange(
                $pedido,
                $oldStatus,
                $newStatus,
                $changedBy,
                $source,
                $reason
            );
        }

        return $pedido->fresh();
    }

    /**
     * Bulk update status for multiple pedidos.
     */
    public function bulkUpdateStatus(
        array $pedidoIds,
        int $newStatusValue,
        User $changedBy
    ): array {
        $results = [
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $newStatus = PedidoStatus::from($newStatusValue);

        DB::transaction(function () use ($pedidoIds, $newStatus, $changedBy, &$results) {
            foreach ($pedidoIds as $id) {
                try {
                    $pedido = Pedido::find($id);

                    if (!$pedido) {
                        $results['errors'][] = "Pedido {$id} não encontrado.";
                        continue;
                    }

                    if ($pedido->status->value === $newStatus->value) {
                        $results['skipped']++;
                        continue;
                    }

                    $this->updateStatus($pedido, $newStatus->value, $changedBy, null, 'bulk');
                    $results['updated']++;
                } catch (\Exception $e) {
                    $results['errors'][] = "Erro no pedido {$id}: " . $e->getMessage();
                }
            }
        });

        return $results;
    }
}
