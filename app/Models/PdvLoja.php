<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PdvLoja extends Model
{
    use HasFactory;

    protected $table = 'pdv_lojas';

    protected $fillable = [
        'id_ponto_venda',
        'nome_padronizado',
        'nome_hiper',
        'alias',
        'ativa',
        'fonte',
    ];

    protected function casts(): array
    {
        return [
            'id_ponto_venda' => 'integer',
            'ativa' => 'boolean',
        ];
    }
}
