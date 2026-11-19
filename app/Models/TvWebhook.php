<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TvWebhook extends Model
{
    use HasFactory;

    protected $table = 'tv_webhooks';

    protected $fillable = [
        'name',
        'url',
        'is_active',
        'events',
        'store_ids',
        'only_during_business_hours',
        'cooldown_seconds',
        'custom_headers',
        'last_triggered_at',
        'last_status_code',
        'failure_count',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'events' => 'array',
            'store_ids' => 'array',
            'only_during_business_hours' => 'boolean',
            'cooldown_seconds' => 'integer',
            'custom_headers' => 'array',
            'last_triggered_at' => 'datetime',
            'last_status_code' => 'integer',
            'failure_count' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(TvWebhookDelivery::class, 'tv_webhook_id');
    }

    public function subscribesTo(string $eventType): bool
    {
        $events = is_array($this->events) ? $this->events : [];

        return in_array('*', $events, true) || in_array($eventType, $events, true);
    }

    public function matchesStore(?int $storeId): bool
    {
        $storeIds = is_array($this->store_ids) ? array_map('intval', $this->store_ids) : [];
        if ($storeIds === []) {
            return true;
        }
        if ($storeId === null) {
            return true;
        }

        return in_array($storeId, $storeIds, true);
    }
}
