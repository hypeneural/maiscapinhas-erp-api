<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model: WheelInventory
 * 
 * Controle de limite/estoque de prêmios por campanha.
 */
class WheelInventory extends Model
{
    protected $table = 'wheel_inventory';

    protected $fillable = [
        'campaign_id',
        'prize_id',
        'total_limit',
        'remaining',
        'daily_limit',
        'daily_remaining',
        'reset_daily_at',
    ];

    protected $casts = [
        'total_limit' => 'integer',
        'remaining' => 'integer',
        'daily_limit' => 'integer',
        'daily_remaining' => 'integer',
        'reset_daily_at' => 'datetime',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WheelCampaign::class, 'campaign_id');
    }

    public function prize(): BelongsTo
    {
        return $this->belongsTo(WheelPrize::class, 'prize_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeByCampaign($query, int $campaignId)
    {
        return $query->where('campaign_id', $campaignId);
    }

    public function scopeAvailable($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('remaining')
                ->orWhere('remaining', '>', 0);
        })->where(function ($q) {
            $q->whereNull('daily_remaining')
                ->orWhere('daily_remaining', '>', 0);
        });
    }

    // ========================================
    // Methods
    // ========================================

    /**
     * Verifica se há estoque disponível.
     */
    public function hasStock(): bool
    {
        // Verifica limite total
        if ($this->total_limit !== null && $this->remaining <= 0) {
            return false;
        }

        // Verifica limite diário
        if ($this->daily_limit !== null && $this->daily_remaining <= 0) {
            return false;
        }

        return true;
    }

    /**
     * Consome uma unidade do estoque.
     */
    public function consume(): bool
    {
        if (!$this->hasStock()) {
            return false;
        }

        if ($this->remaining !== null) {
            $this->remaining--;
        }

        if ($this->daily_remaining !== null) {
            $this->daily_remaining--;
        }

        return $this->save();
    }

    /**
     * Adiciona unidades ao estoque.
     */
    public function addStock(int $quantity): bool
    {
        if ($this->remaining !== null) {
            $newRemaining = $this->remaining + $quantity;

            // Não pode exceder total_limit
            if ($this->total_limit !== null && $newRemaining > $this->total_limit) {
                $newRemaining = $this->total_limit;
            }

            $this->remaining = $newRemaining;
        }

        return $this->save();
    }

    /**
     * Reseta o limite diário.
     */
    public function resetDaily(): bool
    {
        if ($this->daily_limit !== null) {
            $this->daily_remaining = $this->daily_limit;
            $this->reset_daily_at = now();
        }

        return $this->save();
    }

    /**
     * Verifica se precisa resetar o limite diário (novo dia).
     */
    public function needsDailyReset(): bool
    {
        if ($this->daily_limit === null) {
            return false;
        }

        if ($this->reset_daily_at === null) {
            return true;
        }

        return !$this->reset_daily_at->isToday();
    }

    /**
     * Reseta automaticamente se for um novo dia.
     */
    public function autoResetIfNeeded(): void
    {
        if ($this->needsDailyReset()) {
            $this->resetDaily();
        }
    }

    /**
     * Retorna o percentual restante do total.
     */
    public function getRemainingPercentage(): ?float
    {
        if ($this->total_limit === null || $this->total_limit === 0) {
            return null;
        }

        return round(($this->remaining / $this->total_limit) * 100, 2);
    }
}
