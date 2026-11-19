<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TvWebhookDelivery extends Model
{
    use HasFactory;

    protected $table = 'tv_webhook_deliveries';

    public $timestamps = false;

    protected $fillable = [
        'delivery_uuid',
        'tv_webhook_id',
        'target_url',
        'event_type',
        'screen_slug',
        'store_id',
        'status',
        'attempt',
        'http_status',
        'duration_ms',
        'payload',
        'response_excerpt',
        'error_message',
        'dispatched_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'payload' => 'array',
            'dispatched_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(TvWebhook::class, 'tv_webhook_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
