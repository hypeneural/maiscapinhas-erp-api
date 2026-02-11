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
        'user_id',
        'active',
        'source',
        'confidence',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
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
}

