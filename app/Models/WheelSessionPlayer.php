<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlayerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Model: WheelSessionPlayer
 * 
 * Representa a participação de um jogador em uma sessão específica.
 * Pivot entre WheelSession e WheelPlayer.
 */
class WheelSessionPlayer extends Model
{
    protected $table = 'wheel_session_players';

    protected $fillable = [
        'session_player_key',
        'session_id',
        'player_id',
        'status',
        'queue_position',
        'access_token_hash',
        'device_info',
        'terms_version',
        'terms_accepted_at',
        'ip_address',
        'user_agent',
        'joined_at',
        'left_at',
    ];

    protected $casts = [
        'status' => PlayerStatus::class,
        'device_info' => 'array',
        'terms_accepted_at' => 'datetime',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token_hash',
    ];

    // ========================================
    // Boot
    // ========================================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($sessionPlayer) {
            if (empty($sessionPlayer->session_player_key)) {
                $sessionPlayer->session_player_key = 'sp_' . Str::random(12);
            }
            if (empty($sessionPlayer->joined_at)) {
                $sessionPlayer->joined_at = now();
            }
        });
    }

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

    public function spins(): HasMany
    {
        return $this->hasMany(WheelSpin::class, 'session_player_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            PlayerStatus::COMPLETED,
            PlayerStatus::LEFT,
            PlayerStatus::TIMEOUT,
        ]);
    }

    public function scopeInSession($query, int $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    public function scopeInQueue($query)
    {
        return $query->whereIn('status', [
            PlayerStatus::VERIFIED,
            PlayerStatus::SPINNING,
        ])->orderBy('queue_position');
    }

    // ========================================
    // Token Methods
    // ========================================

    /**
     * Gera novo access token e retorna em texto plano.
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

    // ========================================
    // Queue Methods
    // ========================================

    /**
     * Verifica se é a vez deste jogador.
     */
    public function isMyTurn(): bool
    {
        return $this->queue_position === 0 &&
            $this->status === PlayerStatus::VERIFIED;
    }

    /**
     * Pode realizar um spin?
     */
    public function canSpin(): bool
    {
        return $this->isMyTurn() && $this->getSpinsAvailable() > 0;
    }

    /**
     * Retorna número de spins disponíveis.
     */
    public function getSpinsAvailable(): int
    {
        $campaign = $this->session->campaign;
        $limit = $campaign->getSetting('per_phone_limit', '1_per_campaign');

        // Spins já realizados nesta sessão
        $spinsInSession = $this->spins()->completed()->count();

        if ($limit === 'unlimited') {
            return max(0, 10 - $spinsInSession); // Cap em 10
        }

        if ($limit === '1_per_campaign') {
            // Verificar todos os spins do player nesta campanha
            $spinsInCampaign = WheelSpin::whereHas('sessionPlayer', function ($q) {
                $q->where('player_id', $this->player_id);
            })
                ->whereHas('sessionPlayer.session', function ($q) use ($campaign) {
                    $q->where('campaign_id', $campaign->id);
                })
                ->completed()
                ->count();

            return max(0, 1 - $spinsInCampaign);
        }

        if ($limit === '1_per_day') {
            // Verificar spins de hoje
            $spinsToday = WheelSpin::whereHas('sessionPlayer', function ($q) {
                $q->where('player_id', $this->player_id);
            })
                ->whereHas('sessionPlayer.session', function ($q) use ($campaign) {
                    $q->where('campaign_id', $campaign->id);
                })
                ->whereDate('created_at', today())
                ->completed()
                ->count();

            return max(0, 1 - $spinsToday);
        }

        return 1;
    }

    /**
     * Avança a fila após jogador terminar.
     */
    public static function advanceQueue(int $sessionId): void
    {
        static::where('session_id', $sessionId)
            ->where('queue_position', '>', 0)
            ->orderBy('queue_position')
            ->decrement('queue_position');
    }

    // ========================================
    // State Methods
    // ========================================

    /**
     * Retorna o estado para o frontend.
     */
    public function getState(): string
    {
        return match ($this->status) {
            PlayerStatus::PENDING => 'PENDING',
            PlayerStatus::VERIFYING => 'VERIFYING',
            PlayerStatus::VERIFIED => $this->queue_position > 0 ? 'IN_QUEUE' : 'READY_TO_SPIN',
            PlayerStatus::SPINNING => 'SPINNING',
            PlayerStatus::WON, PlayerStatus::LOST => 'RESULT',
            PlayerStatus::LEFT, PlayerStatus::TIMEOUT => 'EXPIRED',
            default => 'UNKNOWN',
        };
    }

    /**
     * Retorna dados formatados para API mobile.
     */
    public function toMobileArray(): array
    {
        return [
            'session_player_key' => $this->session_player_key,
            'player_key' => $this->player->player_key,
            'phone_masked' => $this->player->getPhoneMasked(),
            'name' => $this->player->full_name,
            'status' => $this->status->value,
            'state' => $this->getState(),
            'queue_position' => $this->queue_position,
            'spins_available' => $this->getSpinsAvailable(),
            'joined_at' => $this->joined_at?->toISOString(),
        ];
    }
}
