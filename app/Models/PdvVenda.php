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
}
