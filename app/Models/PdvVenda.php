<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdvVenda extends Model
{
    protected $table = 'pdv_vendas';

    protected $guarded = ['id'];

    protected $casts = [
        'data_hora' => 'datetime',
        'last_seen_in_snapshot_at' => 'datetime',
        'total' => 'decimal:2',
    ];

    public function itens(): HasMany
    {
        return $this->hasMany(PdvVendaItem::class, 'store_pdv_id', 'store_pdv_id')
            ->where('id_operacao', $this->id_operacao);
    }

    public function pagamentos(): HasMany
    {
        return $this->hasMany(PdvVendaPagamento::class, 'store_pdv_id', 'store_pdv_id')
            ->where('id_operacao', $this->id_operacao);
    }

    public function loja()
    {
        // PdvLoja::id_ponto_venda <-> PdvVenda::store_pdv_id
        return $this->belongsTo(PdvLoja::class, 'store_pdv_id', 'id_ponto_venda');
    }

    public function turno()
    {
        // PdvTurno::id_turno <-> PdvVenda::id_turno
        // Reforçando com store_pdv_id para garantir unicidade
        return $this->belongsTo(PdvTurno::class, 'id_turno', 'id_turno')
            ->where('store_pdv_id', $this->store_pdv_id);
    }
}
