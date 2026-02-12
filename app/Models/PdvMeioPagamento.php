<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PdvMeioPagamento extends Model
{
    use HasFactory;

    protected $table = 'pdv_meios_pagamento';

    protected $fillable = [
        'id_finalizador',
        'nome_padronizado',
        'nome_hiper',
        'categoria',
        'ativo',
        'fonte',
    ];

    protected function casts(): array
    {
        return [
            'id_finalizador' => 'integer',
            'ativo' => 'boolean',
        ];
    }
}
