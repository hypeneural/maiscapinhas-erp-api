<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdvClosure extends Model
{
    protected $table = 'pdv_closures';

    protected $primaryKey = 'closure_uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'closure_uuid',
        'store_pdv_id',
        'store_id',
        'store_loja_guid',
        'sequencial',
        'periodo',
        'operador_nome',
        'operador_guid',
        'operador_hiper_id',
        'data_hora_fechamento',
        'inicio_min',
        'termino_max',
        'canais_presentes',
        'canal_canonico',
        'total_sistema_caixa',
        'total_sistema_loja',
        'total_sistema_unificado',
        'total_declarado',
        'total_falta',
        'total_sobra',
        'declared_consistent',
        'has_loja_sales',
        'status',
        'last_sync_id',
    ];

    protected $casts = [
        'canais_presentes' => 'array',
        'declared_consistent' => 'boolean',
        'has_loja_sales' => 'boolean',
        'total_sistema_caixa' => 'float',
        'total_sistema_loja' => 'float',
        'total_sistema_unificado' => 'float',
        'total_declarado' => 'float',
        'total_falta' => 'float',
        'total_sobra' => 'float',
        'data_hora_fechamento' => 'datetime',
        'inicio_min' => 'datetime',
        'termino_max' => 'datetime',
    ];

    public function pagamentos(): HasMany
    {
        return $this->hasMany(PdvClosurePagamento::class, 'closure_uuid', 'closure_uuid');
    }
}
