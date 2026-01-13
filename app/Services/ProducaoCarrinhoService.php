<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CapaPersonalizadaStatus;
use App\Enums\ProducaoPedidoStatus;
use App\Models\CapaPersonalizada;
use App\Models\ProducaoPedido;
use App\Models\ProducaoPedidoItem;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProducaoCarrinhoService
{
    public function __construct(
        private readonly ProducaoEventoService $eventoService
    ) {
    }

    /**
     * Get or create an open cart for the current admin.
     */
    public function getOrCreateOpenCart(): ProducaoPedido
    {
        $user = Auth::user();

        // Find existing open cart
        $carrinho = ProducaoPedido::carrinhoAberto()
            ->where('created_by_id', $user->id)
            ->first();

        if ($carrinho) {
            return $carrinho->load(['itens.capaPersonalizada', 'createdBy']);
        }

        // Create new cart
        return DB::transaction(function () use ($user) {
            $carrinho = ProducaoPedido::create([
                'status' => ProducaoPedidoStatus::CARRINHO_ABERTO,
                'created_by_id' => $user->id,
            ]);

            $this->eventoService->logCarrinhoCriado($carrinho->id, $user);

            return $carrinho->load(['itens.capaPersonalizada', 'createdBy']);
        });
    }

    /**
     * Get the current open cart (without creating).
     */
    public function getOpenCart(): ?ProducaoPedido
    {
        $user = Auth::user();

        return ProducaoPedido::carrinhoAberto()
            ->where('created_by_id', $user->id)
            ->with(['itens.capaPersonalizada.customer', 'itens.capaPersonalizada.customerDevice.phoneModel.brand', 'createdBy'])
            ->first();
    }

    /**
     * Add capas to the cart.
     * Returns array with 'added' and 'blocked' capas.
     */
    public function addToCart(array $capaIds): array
    {
        $results = ['added' => [], 'blocked' => []];
        $user = Auth::user();

        // Get or create cart
        $carrinho = $this->getOrCreateOpenCart();

        DB::transaction(function () use ($capaIds, $carrinho, $user, &$results) {
            foreach ($capaIds as $capaId) {
                $capa = CapaPersonalizada::with(['customerDevice.phoneModel.brand'])->find($capaId);

                if (!$capa) {
                    $results['blocked'][] = [
                        'id' => $capaId,
                        'reason' => 'NOT_FOUND',
                        'message' => 'Capa não encontrada',
                    ];
                    continue;
                }

                // Check if can add to cart
                $blockReason = $capa->getCartBlockReason();
                if ($blockReason) {
                    $results['blocked'][] = array_merge(['id' => $capaId], $blockReason);
                    continue;
                }

                // Create cart item
                $item = ProducaoPedidoItem::create([
                    'producao_pedido_id' => $carrinho->id,
                    'capa_personalizada_id' => $capa->id,
                    'phone_brand' => $capa->customerDevice?->phoneModel?->brand?->brand_name,
                    'phone_model' => $capa->customerDevice?->phoneModel?->marketing_name,
                    'qty' => $capa->qty,
                    'observation' => $capa->obs,
                    'photo_url' => $capa->photo_url,
                ]);

                // Update capa status and link to cart
                $capa->update([
                    'status' => CapaPersonalizadaStatus::NO_CARRINHO,
                    'producao_pedido_id' => $carrinho->id,
                ]);

                // Log events
                $this->eventoService->logItemAdicionado(
                    $carrinho->id,
                    $capa->id,
                    $capa->customerDevice?->phoneModel?->marketing_name ?? 'N/A',
                    $user
                );

                $this->eventoService->logCapaAdicionadaCarrinho($capa->id, $carrinho->id, $user);

                $results['added'][] = $capaId;
            }
        });

        return $results;
    }

    /**
     * Validate capas before adding to cart (dry-run).
     * Returns which capas can be added and which are blocked.
     */
    public function validateCapas(array $capaIds): array
    {
        $results = ['eligible' => [], 'blocked' => []];

        foreach ($capaIds as $capaId) {
            $capa = CapaPersonalizada::find($capaId);

            if (!$capa) {
                $results['blocked'][] = [
                    'id' => $capaId,
                    'reason' => 'NOT_FOUND',
                    'message' => 'Capa não encontrada',
                ];
                continue;
            }

            $blockReason = $capa->getCartBlockReason();
            if ($blockReason) {
                $results['blocked'][] = array_merge(['id' => $capaId], $blockReason);
            } else {
                $results['eligible'][] = $capaId;
            }
        }

        return $results;
    }

    /**
     * Remove multiple items from the cart.
     */
    public function bulkRemoveFromCart(array $itemIds): array
    {
        $results = ['removed' => [], 'errors' => []];
        $user = Auth::user();
        $carrinho = $this->getOpenCart();

        if (!$carrinho) {
            throw ValidationException::withMessages([
                'carrinho' => ['Nenhum carrinho aberto encontrado.'],
            ]);
        }

        DB::transaction(function () use ($itemIds, $carrinho, $user, &$results) {
            foreach ($itemIds as $itemId) {
                $item = $carrinho->itens()->find($itemId);

                if (!$item) {
                    $results['errors'][] = [
                        'id' => $itemId,
                        'message' => 'Item não encontrado no carrinho.',
                    ];
                    continue;
                }

                $capaId = $item->capa_personalizada_id;
                $capa = $item->capaPersonalizada;

                // Delete item
                $item->delete();

                // Revert capa status
                if ($capa) {
                    $capa->update([
                        'status' => CapaPersonalizadaStatus::ENCOMENDA_SOLICITADA,
                        'producao_pedido_id' => null,
                    ]);
                }

                // Log event
                $this->eventoService->logItemRemovido($carrinho->id, $capaId, $user);

                $results['removed'][] = $itemId;
            }
        });

        return $results;
    }

    /**
     * Remove an item from the cart.
     */
    public function removeFromCart(int $itemId): bool
    {
        $user = Auth::user();
        $carrinho = $this->getOpenCart();

        if (!$carrinho) {
            throw ValidationException::withMessages([
                'carrinho' => ['Nenhum carrinho aberto encontrado.'],
            ]);
        }

        $item = $carrinho->itens()->find($itemId);

        if (!$item) {
            throw ValidationException::withMessages([
                'item' => ['Item não encontrado no carrinho.'],
            ]);
        }

        return DB::transaction(function () use ($item, $carrinho, $user) {
            $capaId = $item->capa_personalizada_id;
            $capa = $item->capaPersonalizada;

            // Delete item
            $item->delete();

            // Revert capa status
            if ($capa) {
                $capa->update([
                    'status' => CapaPersonalizadaStatus::ENCOMENDA_SOLICITADA,
                    'producao_pedido_id' => null,
                ]);
            }

            // Log event
            $this->eventoService->logItemRemovido($carrinho->id, $capaId, $user);

            return true;
        });
    }

    /**
     * Close the cart and create the production order.
     */
    public function closeCart(?string $observation = null): ProducaoPedido
    {
        $user = Auth::user();
        $carrinho = $this->getOpenCart();

        if (!$carrinho) {
            throw ValidationException::withMessages([
                'carrinho' => ['Nenhum carrinho aberto encontrado.'],
            ]);
        }

        if ($carrinho->itens->isEmpty()) {
            throw ValidationException::withMessages([
                'carrinho' => ['Carrinho está vazio.'],
            ]);
        }

        return DB::transaction(function () use ($carrinho, $observation, $user) {
            $totalItens = $carrinho->itens->count();
            $totalQtd = $carrinho->itens->sum('qty');

            // Update order status
            $carrinho->update([
                'status' => ProducaoPedidoStatus::ENCOMENDA_REALIZADA,
                'total_itens' => $totalItens,
                'total_qtd' => $totalQtd,
                'observation' => $observation,
                'closed_at' => now(),
            ]);

            // Update all capas status
            foreach ($carrinho->itens as $item) {
                $capa = $item->capaPersonalizada;
                if ($capa) {
                    $fromStatus = $capa->status->value;

                    $capa->update([
                        'status' => CapaPersonalizadaStatus::ENVIADO_PRODUCAO,
                        'sended_to_production_at' => now(),
                    ]);

                    // Log capa event
                    $this->eventoService->logCapaEnviadaFabrica(
                        $capa->id,
                        $carrinho->id,
                        $fromStatus,
                        CapaPersonalizadaStatus::ENVIADO_PRODUCAO->value,
                        $user
                    );
                }
            }

            // Log order event
            $this->eventoService->logCarrinhoFechado($carrinho->id, $totalItens, $totalQtd, $user);

            return $carrinho->fresh(['itens', 'createdBy', 'eventos']);
        });
    }

    /**
     * Cancel an open cart.
     */
    public function cancelCart(): bool
    {
        $user = Auth::user();
        $carrinho = $this->getOpenCart();

        if (!$carrinho) {
            return false;
        }

        return DB::transaction(function () use ($carrinho, $user) {
            // Revert all capas
            foreach ($carrinho->itens as $item) {
                $capa = $item->capaPersonalizada;
                if ($capa) {
                    $capa->update([
                        'status' => CapaPersonalizadaStatus::ENCOMENDA_SOLICITADA,
                        'producao_pedido_id' => null,
                    ]);
                }
            }

            // Delete all items
            $carrinho->itens()->delete();

            // Cancel order
            $carrinho->update([
                'status' => ProducaoPedidoStatus::CANCELADO,
            ]);

            return true;
        });
    }
}
