<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdvCashRule extends Model
{
    protected $table = 'pdv_cash_rules';

    protected $fillable = [
        'store_pdv_id',
        'include_loja_sales_in_cash',
        'extra_config',
    ];

    protected $casts = [
        'include_loja_sales_in_cash' => 'boolean',
        'extra_config' => 'array',
    ];
}
