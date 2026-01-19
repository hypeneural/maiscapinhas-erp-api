<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlayerStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Model: WheelPlayer
 * 
 * Jogador participando de uma sessão da roleta.
 */
class WheelPlayer extends Model
{
    use HasFactory;

    protected $table = 'wheel_players';

    protected $fillable = [
        'player_key',
        'session_id',
        'phone',
        'phone_masked',
        'phone_hash',
        'status',
        'queue_position',
        'access_token_hash',
        'phone_verified',
        'phone_verified_at',
        'ip_address',
        'user_agent',
        'terms_version',
        'terms_accepted_at',
    ];

    protected $casts = [
        'status' => PlayerStatus::class,
        'phone_verified' => 'boolean',
        'phone_verified_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
    ];

    protected $hidden = [
        'phone',
        'phone_hash',
        'access_token_hash',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function session(): BelongsTo
    {
        return $this->belongsTo(WheelSession::class, 'session_id');
    }

    public function spins(): HasMany
    {
        return $this->hasMany(WheelSpin::class, 'player_id');
    }

    public function phoneChallenges(): HasMany
    {
        return $this->hasMany(WheelPhoneChallenge::class, 'player_id');
    }

    public function latestChallenge(): HasOne
    {
        return $this->hasOne(WheelPhoneChallenge::class, 'player_id')
            ->latestOfMany();
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeVerified($query)
    {
        return $query->where('phone_verified', true);
    }

    public function scopeBySession($query, int $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            PlayerStatus::LEFT,
            PlayerStatus::TIMEOUT,
            PlayerStatus::WON,
            PlayerStatus::LOST,
        ]);
    }

    public function scopeByPhone($query, string $phone)
    {
        return $query->where('phone_hash', self::hashPhone($phone));
    }

    // ========================================
    // Methods
    // ========================================

    /**
     * Gera access token e retorna o valor em texto plano (apenas 1x).
     */
    public function generateAccessToken(): string
    {
        $plainToken = Str::random(64);
        $this->access_token_hash = Hash::make($plainToken);
        $this->save();

        return $plainToken;
    }

    /**
     * Verifica se o token é válido.
     */
    public function verifyAccessToken(string $token): bool
    {
        if (!$this->access_token_hash) {
            return false;
        }

        return Hash::check($token, $this->access_token_hash);
    }

    /**
     * Marca o telefone como verificado.
     */
    public function markPhoneVerified(): void
    {
        $this->phone_verified = true;
        $this->phone_verified_at = now();
        $this->status = PlayerStatus::VERIFIED;
        $this->save();
    }

    /**
     * Verifica se pode girar.
     */
    public function canSpin(): bool
    {
        return $this->status->canSpin();
    }

    /**
     * Mascara o telefone para exibição.
     */
    public static function maskPhone(string $phone): string
    {
        // +5548999999999 → +55 48 *****-9999
        $clean = preg_replace('/[^0-9+]/', '', $phone);

        if (strlen($clean) < 10) {
            return '***-****';
        }

        $lastFour = substr($clean, -4);
        $ddd = substr($clean, 2, 2);

        return "+55 {$ddd} *****-{$lastFour}";
    }

    /**
     * Hash do telefone para busca.
     */
    public static function hashPhone(string $phone): string
    {
        $clean = preg_replace('/[^0-9+]/', '', $phone);
        return hash('sha256', $clean);
    }

    /**
     * Gera player_key único.
     */
    public static function generatePlayerKey(): string
    {
        return 'player_' . Str::random(12);
    }

    /**
     * Cria um novo jogador para a sessão.
     */
    public static function createForSession(
        WheelSession $session,
        string $phone,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): self {
        // Próxima posição na fila
        $nextPosition = $session->players()->max('queue_position') + 1;

        return self::create([
            'player_key' => self::generatePlayerKey(),
            'session_id' => $session->id,
            'phone' => $phone,
            'phone_masked' => self::maskPhone($phone),
            'phone_hash' => self::hashPhone($phone),
            'status' => PlayerStatus::PENDING,
            'queue_position' => $nextPosition,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * Verifica se o telefone já participou da campanha.
     */
    public static function hasParticipatedInCampaign(string $phone, int $campaignId): bool
    {
        $phoneHash = self::hashPhone($phone);

        return self::where('phone_hash', $phoneHash)
            ->whereHas('session', fn($q) => $q->where('campaign_id', $campaignId))
            ->whereIn('status', [PlayerStatus::WON, PlayerStatus::LOST])
            ->exists();
    }
}
