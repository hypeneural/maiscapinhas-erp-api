<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProducaoPedidoStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProducaoPedido extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'producao_pedidos';

    protected $fillable = [
        'status',
        'total_itens',
        'total_qtd',
        'factory_total',
        'factory_notes',
        'observation',
        'closed_at',
        'accepted_at',
        'dispatched_at',
        'received_at',
        'created_by_id',
    ];

    protected $casts = [
        'status' => ProducaoPedidoStatus::class,
        'total_itens' => 'integer',
        'total_qtd' => 'integer',
        'factory_total' => 'decimal:2',
        'closed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ProducaoPedidoItem::class, 'producao_pedido_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(ProducaoEvento::class, 'entity_id')
            ->where('entity_type', 'producao_pedido')
            ->orderBy('created_at', 'desc');
    }

    public function capasPersonalizadas(): HasMany
    {
        return $this->hasMany(CapaPersonalizada::class, 'producao_pedido_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeCarrinhoAberto($query)
    {
        return $query->where('status', ProducaoPedidoStatus::CARRINHO_ABERTO);
    }

    public function scopeVisibleToFactory($query)
    {
        return $query->whereNotIn('status', [
            ProducaoPedidoStatus::CARRINHO_ABERTO,
            ProducaoPedidoStatus::CANCELADO,
        ]);
    }

    public function scopeByStatus($query, ?int $status)
    {
        if ($status === null) {
            return $query;
        }
        return $query->where('status', $status);
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

    // ========================================
    // Helpers
    // ========================================

    public function isCarrinhoAberto(): bool
    {
        return $this->status === ProducaoPedidoStatus::CARRINHO_ABERTO;
    }

    public function canAccept(): bool
    {
        return $this->status === ProducaoPedidoStatus::ENCOMENDA_REALIZADA;
    }

    public function canDispatch(): bool
    {
        return $this->status === ProducaoPedidoStatus::PEDIDO_ACEITO;
    }

    public function canReceive(): bool
    {
        return $this->status === ProducaoPedidoStatus::PEDIDO_DESPACHADO;
    }

    public function canCancel(): bool
    {
        return in_array($this->status, [
            ProducaoPedidoStatus::CARRINHO_ABERTO,
            ProducaoPedidoStatus::ENCOMENDA_REALIZADA,
            ProducaoPedidoStatus::PEDIDO_ACEITO,
        ]);
    }

    /**
     * Recalcula os totais baseado nos itens
     */
    public function recalculateTotals(): void
    {
        $this->total_itens = $this->itens()->count();
        $this->total_qtd = $this->itens()->sum('qty');
        $this->saveQuietly();
    }
}
