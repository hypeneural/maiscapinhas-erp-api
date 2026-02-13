<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdvStoreMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'pdv_store_id',
        'store_id',
        'alias',
        'cnpj',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'pdv_store_id' => 'integer',
            'store_id' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
