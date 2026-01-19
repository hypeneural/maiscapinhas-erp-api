<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Model: WheelPrizeState
 * 
 * Estado de execução por prêmio/campanha/escopo.
 * Atualizada automaticamente a cada spin para performance.
 */
class WheelPrizeState extends Model
{
    protected $table = 'wheel_prize_state';

    protected $fillable = [
        'campaign_id',
        'prize_id',
        'scope_id',
        'last_awarded_spin_seq',
        'last_awarded_at',
        'awarded_count_hour',
        'awarded_count_day',
        'awarded_count_total',
        'hour_key',
        'day_key',
    ];

    protected $casts = [
        'scope_id' => 'integer',
        'last_awarded_spin_seq' => 'integer',
        'last_awarded_at' => 'datetime',
        'awarded_count_hour' => 'integer',
        'awarded_count_day' => 'integer',
        'awarded_count_total' => 'integer',
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
    // Factory Methods
    // ========================================

    /**
     * Busca ou cria estado para um prêmio/campanha/escopo.
     */
    public static function getOrCreate(int $campaignId, int $prizeId, ?int $scopeId = null): self
    {
        return static::firstOrCreate([
            'campaign_id' => $campaignId,
            'prize_id' => $prizeId,
            'scope_id' => $scopeId,
        ], [
            'awarded_count_hour' => 0,
            'awarded_count_day' => 0,
            'awarded_count_total' => 0,
            'hour_key' => now()->format('YmdH'),
            'day_key' => now()->format('Ymd'),
        ]);
    }

    // ========================================
    // State Methods
    // ========================================

    /**
     * Reseta contadores se mudou o período.
     */
    public function autoResetCounters(): void
    {
        $currentHourKey = now()->format('YmdH');
        $currentDayKey = now()->format('Ymd');

        $changed = false;

        // Reset horário
        if ($this->hour_key !== $currentHourKey) {
            $this->hour_key = $currentHourKey;
            $this->awarded_count_hour = 0;
            $changed = true;
        }

        // Reset diário
        if ($this->day_key !== $currentDayKey) {
            $this->day_key = $currentDayKey;
            $this->awarded_count_day = 0;
            $changed = true;
        }

        if ($changed) {
            $this->save();
        }
    }

    /**
     * Registra que o prêmio foi sorteado.
     */
    public function recordAward(int $spinSeq): void
    {
        // Auto-reset se necessário
        $this->autoResetCounters();

        $this->last_awarded_spin_seq = $spinSeq;
        $this->last_awarded_at = now();
        $this->awarded_count_hour++;
        $this->awarded_count_day++;
        $this->awarded_count_total++;
        $this->save();
    }

    /**
     * Reset manual do cooldown (admin).
     */
    public function resetCooldown(): void
    {
        $this->last_awarded_spin_seq = null;
        $this->last_awarded_at = null;
        $this->save();
    }

    /**
     * Reset completo (admin).
     */
    public function resetAll(): void
    {
        $this->last_awarded_spin_seq = null;
        $this->last_awarded_at = null;
        $this->awarded_count_hour = 0;
        $this->awarded_count_day = 0;
        $this->hour_key = now()->format('YmdH');
        $this->day_key = now()->format('Ymd');
        $this->save();
    }

    // ========================================
    // Eligibility Checks
    // ========================================

    /**
     * Verifica se respeita cooldown por spins.
     */
    public function respectsSpinCooldown(int $currentSpinSeq, int $minGapSpins): bool
    {
        if ($minGapSpins <= 0) {
            return true;
        }

        if ($this->last_awarded_spin_seq === null) {
            return true; // Nunca saiu
        }

        $gap = $currentSpinSeq - $this->last_awarded_spin_seq;
        return $gap >= $minGapSpins;
    }

    /**
     * Verifica se respeita cooldown por tempo.
     */
    public function respectsTimeCooldown(int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0) {
            return true;
        }

        if ($this->last_awarded_at === null) {
            return true; // Nunca saiu
        }

        $secondsSince = now()->diffInSeconds($this->last_awarded_at);
        return $secondsSince >= $cooldownSeconds;
    }

    /**
     * Verifica se respeita limite por hora.
     */
    public function respectsHourlyLimit(int $maxPerHour): bool
    {
        $this->autoResetCounters();
        return $this->awarded_count_hour < $maxPerHour;
    }

    /**
     * Verifica se respeita limite por dia.
     */
    public function respectsDailyLimit(int $maxPerDay): bool
    {
        $this->autoResetCounters();
        return $this->awarded_count_day < $maxPerDay;
    }

    /**
     * Calcula quantos spins faltam para ficar elegível.
     */
    public function getSpinsUntilEligible(int $currentSpinSeq, int $minGapSpins): int
    {
        if ($minGapSpins <= 0 || $this->last_awarded_spin_seq === null) {
            return 0;
        }

        $gap = $currentSpinSeq - $this->last_awarded_spin_seq;
        return max(0, $minGapSpins - $gap);
    }

    /**
     * Calcula segundos até ficar elegível.
     */
    public function getSecondsUntilEligible(int $cooldownSeconds): int
    {
        if ($cooldownSeconds <= 0 || $this->last_awarded_at === null) {
            return 0;
        }

        $elapsed = now()->diffInSeconds($this->last_awarded_at);
        return max(0, $cooldownSeconds - $elapsed);
    }

    /**
     * Retorna próxima data/hora que ficará elegível.
     */
    public function getNextEligibleAt(int $cooldownSeconds): ?Carbon
    {
        if ($cooldownSeconds <= 0 || $this->last_awarded_at === null) {
            return null;
        }

        $nextEligible = $this->last_awarded_at->copy()->addSeconds($cooldownSeconds);

        return $nextEligible->isFuture() ? $nextEligible : null;
    }

    /**
     * Retorna array com estado formatado para API.
     */
    public function toStateArray(int $currentSpinSeq, ?WheelPrizeRule $rule = null): array
    {
        $this->autoResetCounters();

        return [
            'last_awarded_at' => $this->last_awarded_at?->toISOString(),
            'last_awarded_spin_seq' => $this->last_awarded_spin_seq,
            'awarded_count_hour' => $this->awarded_count_hour,
            'awarded_count_day' => $this->awarded_count_day,
            'awarded_count_total' => $this->awarded_count_total,
            'spins_until_eligible' => $rule ? $this->getSpinsUntilEligible($currentSpinSeq, $rule->min_gap_spins) : 0,
            'seconds_until_eligible' => $rule ? $this->getSecondsUntilEligible($rule->cooldown_seconds) : 0,
            'next_eligible_at' => $rule ? $this->getNextEligibleAt($rule->cooldown_seconds)?->toISOString() : null,
        ];
    }
}
