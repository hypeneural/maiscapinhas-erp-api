<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model: WheelPrizeRule
 * 
 * Regras avançadas de elegibilidade por prêmio/campanha.
 * Configurável pelo Super Admin.
 */
class WheelPrizeRule extends Model
{
    protected $table = 'wheel_prize_rules';

    protected $fillable = [
        'campaign_id',
        'prize_id',
        'min_gap_spins',
        'cooldown_seconds',
        'max_per_hour',
        'max_per_day',
        'cooldown_scope',
        'pacing_enabled',
        'pacing_buffer',
        'priority',
        'active',
    ];

    protected $casts = [
        'min_gap_spins' => 'integer',
        'cooldown_seconds' => 'integer',
        'max_per_hour' => 'integer',
        'max_per_day' => 'integer',
        'pacing_enabled' => 'boolean',
        'pacing_buffer' => 'decimal:2',
        'priority' => 'integer',
        'active' => 'boolean',
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

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeForCampaign($query, int $campaignId)
    {
        return $query->where('campaign_id', $campaignId);
    }

    // ========================================
    // Helper Methods
    // ========================================

    /**
     * Verifica se tem cooldown por spins configurado.
     */
    public function hasSpinCooldown(): bool
    {
        return $this->min_gap_spins > 0;
    }

    /**
     * Verifica se tem cooldown por tempo configurado.
     */
    public function hasTimeCooldown(): bool
    {
        return $this->cooldown_seconds > 0;
    }

    /**
     * Verifica se tem limite por hora.
     */
    public function hasHourlyLimit(): bool
    {
        return $this->max_per_hour !== null && $this->max_per_hour > 0;
    }

    /**
     * Verifica se tem limite por dia.
     */
    public function hasDailyLimit(): bool
    {
        return $this->max_per_day !== null && $this->max_per_day > 0;
    }

    /**
     * Retorna se o escopo é por tela.
     */
    public function isScopedByScreen(): bool
    {
        return $this->cooldown_scope === 'screen';
    }

    /**
     * Retorna resumo da regra para exibição.
     */
    public function getSummary(): array
    {
        $parts = [];

        if ($this->hasSpinCooldown()) {
            $parts[] = "{$this->min_gap_spins} spins";
        }

        if ($this->hasTimeCooldown()) {
            $minutes = ceil($this->cooldown_seconds / 60);
            $parts[] = "{$minutes} min";
        }

        if ($this->hasHourlyLimit()) {
            $parts[] = "{$this->max_per_hour}/hora";
        }

        if ($this->hasDailyLimit()) {
            $parts[] = "{$this->max_per_day}/dia";
        }

        return [
            'cooldown' => implode(' + ', array_slice($parts, 0, 2)) ?: 'Nenhum',
            'limits' => implode(', ', array_slice($parts, 2)) ?: 'Sem limite',
            'scope' => $this->isScopedByScreen() ? 'Por tela' : 'Global',
            'pacing' => $this->pacing_enabled ? "Ativo ({$this->pacing_buffer}x)" : 'Desativado',
        ];
    }
}
