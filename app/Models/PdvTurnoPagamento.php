<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdvTurnoPagamento extends Model
{
    protected $table = 'pdv_turno_pagamentos';

    protected $guarded = ['id'];

    protected $casts = [
        'total' => 'decimal:2',
    ];

    public function turno()
    {
        return $this->belongsTo(PdvTurno::class, 'id_turno', 'id_turno');
    }
}
