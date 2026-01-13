<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CapaPersonalizadaStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CapaPersonalizada extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'capas_personalizadas';

    protected $fillable = [
        'store_id',
        'user_id',
        'customer_id',
        'customer_device_id',
        'selected_product',
        'product_reference',
        'obs',
        'photo_path',
        'upload_token',
        'upload_token_expires_at',
        'qty',
        'price',
        'payed',
        'payday',
        'received_by_id',
        'sended_to_production_at',
        'producao_pedido_id',
        'status',
        'created_by_id',
        'updated_by_id',
    ];

    protected $casts = [
        'qty' => 'integer',
        'price' => 'decimal:2',
        'payed' => 'boolean',
        'payday' => 'date',
        'sended_to_production_at' => 'date',
        'upload_token_expires_at' => 'datetime',
        'status' => CapaPersonalizadaStatus::class,
    ];

    // ========================================
    // Relationships
    // ========================================

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerDevice(): BelongsTo
    {
        return $this->belongsTo(CustomerDevice::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    public function producaoPedido(): BelongsTo
    {
        return $this->belongsTo(ProducaoPedido::class, 'producao_pedido_id');
    }

    public function eventos()
    {
        return ProducaoEvento::forCapaPersonalizada($this->id)->get();
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeForStore($query, ?int $storeId)
    {
        if (!$storeId) {
            return $query;
        }
        return $query->where('store_id', $storeId);
    }

    public function scopeForUser($query, ?int $userId)
    {
        if (!$userId) {
            return $query;
        }
        return $query->where('user_id', $userId);
    }

    public function scopeWithStatus($query, $status)
    {
        if ($status === null) {
            return $query;
        }
        return $query->where('status', $status);
    }

    public function scopeForCustomer($query, ?int $customerId)
    {
        if (!$customerId) {
            return $query;
        }
        return $query->where('customer_id', $customerId);
    }

    public function scopeCreatedBetween($query, ?string $from, ?string $to)
    {
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }
        return $query;
    }

    public function scopePayed($query, ?bool $payed)
    {
        if ($payed === null) {
            return $query;
        }
        return $query->where('payed', $payed);
    }

    public function scopePayday($query, ?string $payday)
    {
        if (!$payday) {
            return $query;
        }
        return $query->whereDate('payday', $payday);
    }

    public function scopeReceivedBy($query, ?int $receivedById)
    {
        if (!$receivedById) {
            return $query;
        }
        return $query->where('received_by_id', $receivedById);
    }

    public function scopeSearch($query, ?string $term)
    {
        if (!$term) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            // If term is purely numeric, search by ID as well
            if (ctype_digit($term)) {
                $q->where('id', (int) $term);
            }

            $q->orWhere('selected_product', 'like', "%{$term}%")
                ->orWhere('product_reference', 'like', "%{$term}%")
                ->orWhere('obs', 'like', "%{$term}%")
                ->orWhereHas('customer', function ($cq) use ($term) {
                    $cq->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%");
                });
        });
    }

    public function scopeByDeviceBrand($query, ?int $brandId)
    {
        if (!$brandId) {
            return $query;
        }

        return $query->whereHas('customerDevice.phoneModel', function ($q) use ($brandId) {
            $q->where('brand_id', $brandId);
        });
    }

    public function scopeByDeviceModel($query, ?int $modelId)
    {
        if (!$modelId) {
            return $query;
        }

        return $query->whereHas('customerDevice', function ($q) use ($modelId) {
            $q->where('phone_model_id', $modelId);
        });
    }

    // ========================================
    // Accessors
    // ========================================

    public function getPhotoUrlAttribute(): ?string
    {
        if (!$this->photo_path) {
            return null;
        }
        return asset('storage/' . $this->photo_path);
    }

    public function getTotalAttribute(): ?float
    {
        if ($this->price === null) {
            return null;
        }
        return (float) $this->price * $this->qty;
    }

    // ========================================
    // Upload Token Methods
    // ========================================

    /**
     * Check if the provided token is valid and not expired.
     */
    public function hasValidUploadToken(string $token): bool
    {
        if (!$this->upload_token || !$this->upload_token_expires_at) {
            return false;
        }

        return $this->upload_token === $token
            && $this->upload_token_expires_at->isFuture();
    }

    /**
     * Clear the upload token after successful upload.
     */
    public function clearUploadToken(): void
    {
        $this->update([
            'upload_token' => null,
            'upload_token_expires_at' => null,
        ]);
    }

    // ========================================
    // Production Cart Methods
    // ========================================

    /**
     * Check if capa can be added to cart.
     */
    public function canAddToCart(): bool
    {
        return $this->status->canAddToCart() && $this->photo_path !== null;
    }

    /**
     * Check if capa is currently in a production cart.
     */
    public function isInCart(): bool
    {
        return $this->producao_pedido_id !== null
            && $this->producaoPedido?->isCarrinhoAberto();
    }

    /**
     * Check if capa was ever sent to factory.
     */
    public function wasEverSentToFactory(): bool
    {
        return $this->sended_to_production_at !== null
            || $this->status === CapaPersonalizadaStatus::ENVIADO_PRODUCAO;
    }

    /**
     * Get reason why capa cannot be added to cart.
     */
    public function getCartBlockReason(): ?array
    {
        if ($this->status === CapaPersonalizadaStatus::CANCELADA) {
            return ['reason' => 'CANCELLED', 'message' => 'Capa está cancelada'];
        }

        if (!$this->photo_path) {
            return ['reason' => 'NO_PHOTO', 'message' => 'Capa não possui foto'];
        }

        if ($this->isInCart()) {
            return ['reason' => 'ALREADY_IN_CART', 'message' => 'Capa já está no carrinho'];
        }

        if ($this->wasEverSentToFactory()) {
            return ['reason' => 'ALREADY_SENT', 'message' => 'Capa já foi enviada para fábrica'];
        }

        if (!$this->status->canAddToCart()) {
            return ['reason' => 'INVALID_STATUS', 'message' => 'Status deve ser "Encomenda Solicitada"'];
        }

        return null;
    }

    /**
     * Check if capa is orphaned (linked to a cancelled production order).
     */
    public function isOrphan(): bool
    {
        if (!$this->producao_pedido_id) {
            return false;
        }

        $pedido = $this->producaoPedido;

        if (!$pedido) {
            // Pedido was deleted - this is orphan
            return true;
        }

        // Check if pedido is cancelled
        return $pedido->status === \App\Enums\ProducaoPedidoStatus::CANCELADO;
    }

    /**
     * Release capa from orphan state (cancelled production order).
     * Returns true if capa was released, false if not orphan.
     */
    public function releaseIfOrphan(): bool
    {
        if (!$this->isOrphan()) {
            return false;
        }

        $this->update([
            'producao_pedido_id' => null,
            'status' => CapaPersonalizadaStatus::ENCOMENDA_SOLICITADA,
        ]);

        return true;
    }

    /**
     * Get production history for this capa.
     * Returns array of pedidos this capa has been associated with.
     */
    public function getProducaoHistory(): array
    {
        // Get from producao_pedido_itens (historical snapshots)
        $items = \App\Models\ProducaoPedidoItem::where('capa_personalizada_id', $this->id)
            ->with('producaoPedido:id,status,created_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return $items->map(function ($item) {
            $pedido = $item->producaoPedido;
            return [
                'pedido_id' => $pedido?->id,
                'status' => $pedido?->status?->name ?? 'DELETED',
                'status_label' => $pedido?->status?->label() ?? 'Deletado',
                'added_at' => $item->created_at->toDateString(),
            ];
        })->toArray();
    }
}
