<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TvAd extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tv_ads';

    protected $fillable = [
        'title',
        'media_type',
        'media_url',
        'media_path',
        'thumbnail_url',
        'duration_seconds',
        'target_orientation',
        'is_global',
        'status',
        'starts_at',
        'ends_at',
        'days_of_week',
        'order_priority',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_global' => 'boolean',
            'duration_seconds' => 'integer',
            'order_priority' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'days_of_week' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function targetStores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'tv_ad_store_targets', 'tv_ad_id', 'store_id')
            ->withTimestamps();
    }

    public function storeTargets(): HasMany
    {
        return $this->hasMany(TvAdStoreTarget::class, 'tv_ad_id');
    }

    /**
     * Scope para anúncios atualmente válidos e em período de exibição.
     */
    public function scopeCurrentlyActive(Builder $query): Builder
    {
        $now = Carbon::now();

        return $query->where('status', 'active')
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $now);
            });
    }

    /**
     * Scope para anúncios aplicáveis a uma determinada loja (globais OU direcionados à loja).
     */
    public function scopeForStore(Builder $query, ?int $storeId): Builder
    {
        if (!$storeId) {
            return $query->where('is_global', true);
        }

        return $query->where(function (Builder $q) use ($storeId) {
            $q->where('is_global', true)
                ->orWhereHas('targetStores', function (Builder $storeQuery) use ($storeId) {
                    $storeQuery->where('stores.id', $storeId);
                });
        });
    }

    /**
     * Verifica se o anúncio está no dia da semana correto.
     */
    public function isApplicableToday(): bool
    {
        if (empty($this->days_of_week)) {
            return true;
        }

        $currentDayOfWeek = Carbon::now()->dayOfWeek; // 0 = Domingo, 6 = Sábado
        return in_array($currentDayOfWeek, $this->days_of_week, true);
    }
}
