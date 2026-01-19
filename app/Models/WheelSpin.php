<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SpinStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Model: WheelSpin
 * 
 * Histórico de giros da roleta.
 */
class WheelSpin extends Model
{
    use HasFactory;

    protected $table = 'wheel_spins';

    protected $fillable = [
        'spin_key',
        'session_id',
        'player_id',
        'campaign_id',
        'screen_id',
        'status',
        'client_nonce',
        'segment_id',
        'prize_id',
        'prize_code',
        'final_angle',
        'requested_at',
        'started_at',
        'completed_at',
        'animation_duration_ms',
        'telemetry',
        'redeemed',
        'redeemed_at',
        'redeemed_by',
    ];

    protected $casts = [
        'status' => SpinStatus::class,
        'final_angle' => 'decimal:2',
        'requested_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'animation_duration_ms' => 'integer',
        'telemetry' => 'array',
        'redeemed' => 'boolean',
        'redeemed_at' => 'datetime',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function session(): BelongsTo
    {
        return $this->belongsTo(WheelSession::class, 'session_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(WheelPlayer::class, 'player_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WheelCampaign::class, 'campaign_id');
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(WheelScreen::class, 'screen_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(WheelSegment::class, 'segment_id');
    }

    public function prize(): BelongsTo
    {
        return $this->belongsTo(WheelPrize::class, 'prize_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeCompleted($query)
    {
        return $query->where('status', SpinStatus::COMPLETED);
    }

    public function scopeBySession($query, int $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    public function scopeByCampaign($query, int $campaignId)
    {
        return $query->where('campaign_id', $campaignId);
    }

    public function scopeByScreen($query, int $screenId)
    {
        return $query->where('screen_id', $screenId);
    }

    public function scopeByNonce($query, string $nonce)
    {
        return $query->where('client_nonce', $nonce);
    }

    public function scopeWon($query)
    {
        return $query->completed()
            ->whereHas('prize', fn($q) => $q->whereIn('type', ['product', 'coupon']));
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    public function scopeNotRedeemed($query)
    {
        return $query->where('redeemed', false);
    }

    // ========================================
    // Methods
    // ========================================

    /**
     * Verifica se o giro resultou em prêmio resgatável.
     */
    public function hasRedeemablePrize(): bool
    {
        if (!$this->prize) {
            return false;
        }

        return $this->prize->requiresRedeem();
    }

    /**
     * Marca como resgatado.
     */
    public function markRedeemed(?string $redeemedBy = null): bool
    {
        $this->redeemed = true;
        $this->redeemed_at = now();
        $this->redeemed_by = $redeemedBy;

        return $this->save();
    }

    /**
     * Atualiza telemetria do ACK.
     */
    public function updateTelemetry(array $data): void
    {
        $this->telemetry = array_merge($this->telemetry ?? [], $data);

        if (isset($data['animation_duration_ms'])) {
            $this->animation_duration_ms = $data['animation_duration_ms'];
        }

        $this->save();
    }

    /**
     * Marca como spinning (animação iniciada).
     */
    public function markSpinning(): void
    {
        $this->status = SpinStatus::SPINNING;
        $this->started_at = now();
        $this->save();
    }

    /**
     * Marca como completed.
     */
    public function markCompleted(): void
    {
        $this->status = SpinStatus::COMPLETED;
        $this->completed_at = now();
        $this->save();
    }

    /**
     * Gera spin_key único.
     */
    public static function generateSpinKey(): string
    {
        return 'spin_' . Str::random(12);
    }

    /**
     * Encontra por client_nonce (idempotência).
     */
    public static function findByNonce(int $sessionId, string $nonce): ?self
    {
        return self::where('session_id', $sessionId)
            ->where('client_nonce', $nonce)
            ->first();
    }

    /**
     * Verifica se já existe spin em andamento para a sessão.
     */
    public static function hasActiveSpinForSession(int $sessionId): bool
    {
        return self::where('session_id', $sessionId)
            ->whereIn('status', [SpinStatus::PENDING, SpinStatus::PROCESSING, SpinStatus::SPINNING])
            ->exists();
    }

    /**
     * Calcula ângulo final baseado no segmento.
     */
    public function calculateFinalAngle(array $segments, int $targetIndex): float
    {
        $segmentCount = count($segments);
        if ($segmentCount === 0) {
            return 0;
        }

        $segmentAngle = 360 / $segmentCount;
        $minRotations = $this->campaign->getSetting('min_rotations', 5);
        $maxRotations = $this->campaign->getSetting('max_rotations', 8);

        // Rotações completas + offset para acertar o segmento
        $rotations = rand($minRotations, $maxRotations);
        $baseAngle = $rotations * 360;

        // Ângulo do segmento (centro)
        $segmentCenter = ($targetIndex * $segmentAngle) + ($segmentAngle / 2);

        // Adiciona randomização dentro do segmento
        $randomOffset = rand(-15, 15);

        return $baseAngle + $segmentCenter + $randomOffset;
    }
}
