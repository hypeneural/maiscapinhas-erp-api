<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdvSyncPayload extends Model
{
    use HasFactory;

    protected $fillable = [
        'pdv_sync_id',
        'payload',
        'compression',
    ];

    public function pdvSync(): BelongsTo
    {
        return $this->belongsTo(PdvSync::class);
    }
}

