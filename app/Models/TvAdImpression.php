<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TvAdImpression extends Model
{
    use HasFactory;

    protected $table = 'tv_ad_impressions';

    protected $fillable = [
        'tv_ad_id',
        'store_id',
        'screen_slug',
        'duration_played_seconds',
        'completed_naturally',
        'media_type',
        'displayed_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_played_seconds' => 'integer',
            'completed_naturally' => 'boolean',
            'displayed_at' => 'datetime',
        ];
    }

    public function ad(): BelongsTo
    {
        return $this->belongsTo(TvAd::class, 'tv_ad_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
