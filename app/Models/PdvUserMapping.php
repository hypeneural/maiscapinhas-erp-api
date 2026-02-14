<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdvUserMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_pdv_id',
        'pdv_user_id',
        'pdv_user_name',
        'pdv_user_login',
        'user_id',
        'is_store_operator',
        'active',
        'source',
        'confidence',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'is_store_operator' => 'boolean',
            'confidence' => 'integer',
            'store_pdv_id' => 'integer',
            'pdv_user_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function storeMapping(): BelongsTo
    {
        return $this->belongsTo(PdvStoreMapping::class, 'store_pdv_id', 'pdv_store_id');
    }
}
