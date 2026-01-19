<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Model: WheelPhoneChallenge
 * 
 * Desafio de verificação de telefone via WhatsApp/SMS.
 */
class WheelPhoneChallenge extends Model
{
    protected $table = 'wheel_phone_challenges';

    protected $fillable = [
        'player_id',
        'phone',
        'code',
        'method',
        'status',
        'attempts',
        'max_attempts',
        'expires_at',
        'sent_at',
        'verified_at',
        'send_response',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'verified_at' => 'datetime',
        'send_response' => 'array',
    ];

    protected $hidden = [
        'code',
        'send_response',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function player(): BelongsTo
    {
        return $this->belongsTo(WheelPlayer::class, 'player_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopePending($query)
    {
        return $query->whereIn('status', ['pending', 'sent']);
    }

    public function scopeNotExpired($query)
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeByPlayer($query, int $playerId)
    {
        return $query->where('player_id', $playerId);
    }

    // ========================================
    // Methods
    // ========================================

    /**
     * Verifica se expirou.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Verifica se ainda pode tentar.
     */
    public function canAttempt(): bool
    {
        if ($this->isExpired()) {
            return false;
        }

        if ($this->attempts >= $this->max_attempts) {
            return false;
        }

        return in_array($this->status, ['pending', 'sent']);
    }

    /**
     * Tenta verificar o código.
     */
    public function verify(string $inputCode): bool
    {
        if (!$this->canAttempt()) {
            return false;
        }

        $this->attempts++;

        if ($this->code === $inputCode) {
            $this->status = 'verified';
            $this->verified_at = now();
            $this->save();

            // Marca o player como verificado
            $this->player->markPhoneVerified();

            return true;
        }

        if ($this->attempts >= $this->max_attempts) {
            $this->status = 'failed';
        }

        $this->save();

        return false;
    }

    /**
     * Marca como enviado.
     */
    public function markSent(array $response = []): void
    {
        $this->status = 'sent';
        $this->sent_at = now();
        $this->send_response = $response;
        $this->save();
    }

    /**
     * Marca como falha.
     */
    public function markFailed(array $response = []): void
    {
        $this->status = 'failed';
        $this->send_response = $response;
        $this->save();
    }

    /**
     * Gera código de 6 dígitos.
     */
    public static function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Cria um novo challenge para o player.
     */
    public static function createForPlayer(
        WheelPlayer $player,
        string $method = 'whatsapp',
        int $expiresInSeconds = 300,
        int $maxAttempts = 3
    ): self {
        return self::create([
            'player_id' => $player->id,
            'phone' => $player->phone,
            'code' => self::generateCode(),
            'method' => $method,
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'expires_at' => now()->addSeconds($expiresInSeconds),
        ]);
    }

    /**
     * Encontra challenge ativo para o player.
     */
    public static function findActiveForPlayer(int $playerId): ?self
    {
        return self::byPlayer($playerId)
            ->pending()
            ->notExpired()
            ->latest()
            ->first();
    }
}
