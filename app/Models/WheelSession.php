<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Model: WheelSession
 * 
 * Sessão de QR Code ativa por screen.
 */
class WheelSession extends Model
{
    use HasFactory;

    protected $table = 'wheel_sessions';

    protected $fillable = [
        'session_key',
        'screen_id',
        'campaign_id',
        'status',
        'qr_code_data',
        'expires_at',
        'current_player_id',
        'metadata',
    ];

    protected $casts = [
        'status' => SessionStatus::class,
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function screen(): BelongsTo
    {
        return $this->belongsTo(WheelScreen::class, 'screen_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WheelCampaign::class, 'campaign_id');
    }

    public function currentPlayer(): BelongsTo
    {
        return $this->belongsTo(WheelPlayer::class, 'current_player_id');
    }

    public function players(): HasMany
    {
        return $this->hasMany(WheelPlayer::class, 'session_id');
    }

    public function activePlayers(): HasMany
    {
        return $this->players()
            ->whereNotIn('status', ['left', 'timeout', 'won', 'lost'])
            ->orderBy('queue_position');
    }

    public function spins(): HasMany
    {
        return $this->hasMany(WheelSpin::class, 'session_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            SessionStatus::WAITING,
            SessionStatus::ACTIVE,
            SessionStatus::SPINNING,
        ]);
    }

    public function scopeByScreen($query, int $screenId)
    {
        return $query->where('screen_id', $screenId);
    }

    public function scopeNotExpired($query)
    {
        return $query->where('expires_at', '>', now());
    }

    // ========================================
    // Methods
    // ========================================

    /**
     * Verifica se a sessão expirou.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Verifica se pode aceitar novos jogadores.
     */
    public function canJoin(): bool
    {
        if ($this->isExpired()) {
            return false;
        }

        if (!$this->status->canJoin()) {
            return false;
        }

        $maxQueue = $this->campaign->getSetting('max_queue_size', 10);

        return $this->activePlayers()->count() < $maxQueue;
    }

    /**
     * Retorna o próximo jogador da fila.
     */
    public function getNextPlayer(): ?WheelPlayer
    {
        return $this->activePlayers()
            ->where('status', 'verified')
            ->orderBy('queue_position')
            ->first();
    }

    /**
     * Atualiza para status expirado se necessário.
     */
    public function expireIfNeeded(): bool
    {
        if ($this->isExpired() && !$this->status->isTerminal()) {
            $this->status = SessionStatus::EXPIRED;
            return $this->save();
        }

        return false;
    }

    /**
     * Gera QR code data.
     */
    public function generateQrCodeData(string $baseUrl): string
    {
        $data = "{$baseUrl}/wheel/join/{$this->session_key}";
        $this->qr_code_data = $data;
        $this->save();

        return $data;
    }

    /**
     * Gera session_key único.
     */
    public static function generateSessionKey(): string
    {
        return 'sess_' . Str::random(12);
    }

    /**
     * Cria uma nova sessão para a screen.
     */
    public static function createForScreen(WheelScreen $screen, WheelCampaign $campaign): self
    {
        $ttl = $campaign->getSetting('qr_ttl_seconds', 120);

        return self::create([
            'session_key' => self::generateSessionKey(),
            'screen_id' => $screen->id,
            'campaign_id' => $campaign->id,
            'status' => SessionStatus::WAITING,
            'expires_at' => now()->addSeconds($ttl),
        ]);
    }
}
