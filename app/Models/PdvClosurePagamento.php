<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdvClosurePagamento extends Model
{
    protected $table = 'pdv_closure_pagamentos';

    protected $fillable = [
        'closure_uuid',
        'tipo',
        'id_finalizador',
        'meio_pagamento',
        'total',
        'qtd_vendas',
    ];

    protected $casts = [
        'total' => 'float',
        'qtd_vendas' => 'integer',
    ];

    public function closure(): BelongsTo
    {
        return $this->belongsTo(PdvClosure::class, 'closure_uuid', 'closure_uuid');
    }
}
