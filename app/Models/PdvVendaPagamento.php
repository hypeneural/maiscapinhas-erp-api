<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdvVendaPagamento extends Model
{
    protected $table = 'pdv_venda_pagamentos';

    protected $guarded = ['id'];

    protected $casts = [
        'valor' => 'decimal:2',
        'troco' => 'decimal:2',
    ];
}
