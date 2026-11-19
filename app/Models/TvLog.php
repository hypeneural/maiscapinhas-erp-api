<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TvLog extends Model
{
    use HasFactory;

    protected $table = 'tv_logs';

    protected $fillable = [
        'level',
        'event',
        'screen_slug',
        'store_id',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public static function log(string $level, string $event, string $message, ?string $screenSlug = null, ?int $storeId = null, ?array $context = null): self
    {
        return self::create([
            'level' => $level,
            'event' => $event,
            'screen_slug' => $screenSlug,
            'store_id' => $storeId,
            'message' => $message,
            'context' => $context,
        ]);
    }
}
