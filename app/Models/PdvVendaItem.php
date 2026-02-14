<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdvVendaItem extends Model
{
    protected $table = 'pdv_venda_itens';

    protected $guarded = ['id'];

    protected $casts = [
        'total' => 'decimal:2',
        'qtd' => 'decimal:3', // Quantidade pode ser fracionada em alguns casos, mas geralmente int
        'preco_unit' => 'decimal:2',
    ];
}
