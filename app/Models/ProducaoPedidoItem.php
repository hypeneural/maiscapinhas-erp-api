<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProducaoPedidoItem extends Model
{
    use HasFactory;

    protected $table = 'producao_pedido_itens';

    protected $fillable = [
        'producao_pedido_id',
        'capa_personalizada_id',
        'phone_brand',
        'phone_model',
        'qty',
        'observation',
        'photo_url',
    ];

    protected $casts = [
        'qty' => 'integer',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function producaoPedido(): BelongsTo
    {
        return $this->belongsTo(ProducaoPedido::class, 'producao_pedido_id');
    }

    public function capaPersonalizada(): BelongsTo
    {
        return $this->belongsTo(CapaPersonalizada::class, 'capa_personalizada_id');
    }

    // ========================================
    // Boot
    // ========================================

    protected static function booted(): void
    {
        // Ao criar um item, captura snapshot dos dados da capa
        static::creating(function (ProducaoPedidoItem $item) {
            if ($item->capa_personalizada_id && !$item->phone_brand) {
                $capa = CapaPersonalizada::with(['customerDevice.phoneModel.brand'])->find($item->capa_personalizada_id);

                if ($capa) {
                    $item->qty = $item->qty ?: $capa->qty;
                    $item->observation = $item->observation ?: $capa->obs;
                    $item->photo_url = $item->photo_url ?: $capa->photo_url;

                    if ($capa->customerDevice?->phoneModel) {
                        $item->phone_model = $capa->customerDevice->phoneModel->marketing_name;
                        $item->phone_brand = $capa->customerDevice->phoneModel->brand?->brand_name;
                    }
                }
            }
        });

        // Ao criar/deletar, recalcula totais do pedido
        static::created(function (ProducaoPedidoItem $item) {
            $item->producaoPedido->recalculateTotals();
        });

        static::deleted(function (ProducaoPedidoItem $item) {
            $item->producaoPedido->recalculateTotals();
        });
    }
}
