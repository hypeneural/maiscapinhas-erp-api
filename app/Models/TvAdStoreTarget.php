<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TvAdStoreTarget extends Model
{
    use HasFactory;

    protected $table = 'tv_ad_store_targets';

    protected $fillable = [
        'tv_ad_id',
        'store_id',
    ];

    public function ad(): BelongsTo
    {
        return $this->belongsTo(TvAd::class, 'tv_ad_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
