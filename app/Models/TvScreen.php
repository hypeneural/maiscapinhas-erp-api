<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TvScreen extends Model
{
    use HasFactory;

    protected $table = 'tv_screens';

    protected $fillable = [
        'store_id',
        'name',
        'slug',
        'screen_key',
        'access_token',
        'orientation',
        'is_online',
        'is_blackout',
        'current_ad_title',
        'current_ad_id',
        'fps',
        'uptime_seconds',
        'last_ping_at',
        'device_info',
        'design_template',
    ];

    protected function casts(): array
    {
        return [
            'is_online' => 'boolean',
            'is_blackout' => 'boolean',
            'last_ping_at' => 'datetime',
            'device_info' => 'array',
            'design_template' => 'array',
            'uptime_seconds' => 'integer',
            'fps' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TvScreen $screen) {
            if (empty($screen->screen_key)) {
                $screen->screen_key = 'scr_' . Str::lower(Str::random(16));
            }
            if (empty($screen->access_token)) {
                $screen->access_token = 'tk_' . Str::random(32);
            }
            if (empty($screen->slug)) {
                $screen->slug = 'tv-' . Str::slug($screen->name ?: 'screen-' . Str::random(6));
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TvScreenSession::class, 'screen_id');
    }
}
