<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TvScreenSession extends Model
{
    use HasFactory;

    protected $table = 'tv_screen_sessions';

    protected $fillable = [
        'store_id',
        'screen_id',
        'screen_slug',
        'started_at',
        'last_ping_at',
        'ended_at',
        'duration_minutes',
        'ended_reason',
        'device_info',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_ping_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_minutes' => 'integer',
            'device_info' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(TvScreen::class, 'screen_id');
    }

    /**
     * Fechar a sessão ativa.
     */
    public function close(string $reason): void
    {
        $endedAt = now();
        $duration = max(1, (int) $this->started_at->diffInMinutes($endedAt));

        $this->update([
            'ended_at' => $endedAt,
            'duration_minutes' => $duration,
            'ended_reason' => $reason,
        ]);
    }
}
