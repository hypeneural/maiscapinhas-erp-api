<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PdvUsuario extends Model
{
    use HasFactory;

    protected $table = 'pdv_usuarios';

    protected $fillable = [
        'id_usuario_hiper',
        'nome_padronizado',
        'nome_hiper',
        'login_hiper',
        'papel',
        'ativo',
        'fonte',
    ];

    protected function casts(): array
    {
        return [
            'id_usuario_hiper' => 'integer',
            'ativo' => 'boolean',
        ];
    }
}
