<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdvTurno extends Model
{
    protected $table = 'pdv_turnos';

    protected $guarded = ['id'];

    protected $casts = [
        'data_hora_inicio' => 'datetime',
        'data_hora_termino' => 'datetime',
        'fechado' => 'boolean',
        'total_sistema' => 'decimal:2',
        'total_vendas' => 'decimal:2',
    ];
    public function operador()
    {
        return $this->belongsTo(PdvUsuario::class, 'operador_guid', 'guid_usuario');
    }

    public function responsavel()
    {
        return $this->belongsTo(PdvUsuario::class, 'responsavel_guid', 'guid_usuario');
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function pagamentos()
    {
        return $this->hasMany(PdvTurnoPagamento::class, 'id_turno', 'id_turno')
            ->whereColumn('pdv_turno_pagamentos.store_pdv_id', 'pdv_turnos.store_pdv_id')
            ->whereColumn('pdv_turno_pagamentos.canal', 'pdv_turnos.canal');
    }
}
