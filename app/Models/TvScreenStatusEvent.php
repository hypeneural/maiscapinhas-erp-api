<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TvScreenStatusEvent extends Model
{
    use HasFactory;

    protected $table = 'tv_screen_status_events';

    public $timestamps = false;

    protected $fillable = [
        'screen_id',
        'store_id',
        'screen_slug',
        'event_type',
        'previous_status',
        'new_status',
        'reason',
        'duration_previous_seconds',
        'during_business_hours',
        'metadata',
        'occurred_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_previous_seconds' => 'integer',
            'during_business_hours' => 'boolean',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(TvScreen::class, 'screen_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
