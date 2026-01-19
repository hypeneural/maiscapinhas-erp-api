<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Model: WheelEvent
 * 
 * Log de auditoria para eventos do sistema de roleta.
 */
class WheelEvent extends Model
{
    protected $table = 'wheel_events';

    /**
     * Desabilita updated_at pois é apenas log.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'event_id',
        'type',
        'screen_id',
        'campaign_id',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    // ========================================
    // Event Types
    // ========================================

    public const TYPE_SCREEN_CONNECTED = 'screen_connected';
    public const TYPE_SCREEN_DISCONNECTED = 'screen_disconnected';
    public const TYPE_CAMPAIGN_ACTIVATED = 'campaign_activated';
    public const TYPE_CAMPAIGN_PAUSED = 'campaign_paused';
    public const TYPE_CAMPAIGN_ENDED = 'campaign_ended';
    public const TYPE_SPIN_STARTED = 'spin_started';
    public const TYPE_SPIN_COMPLETED = 'spin_completed';
    public const TYPE_PRIZE_WON = 'prize_won';
    public const TYPE_INVENTORY_DEPLETED = 'inventory_depleted';
    public const TYPE_PLAYER_JOINED = 'player_joined';
    public const TYPE_PLAYER_LEFT = 'player_left';
    public const TYPE_CONFIG_CHANGED = 'config_changed';

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

    // ========================================
    // Scopes
    // ========================================

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByScreen($query, int $screenId)
    {
        return $query->where('screen_id', $screenId);
    }

    public function scopeByCampaign($query, int $campaignId)
    {
        return $query->where('campaign_id', $campaignId);
    }

    public function scopeInDateRange($query, string $from, string $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    // ========================================
    // Static Methods
    // ========================================

    /**
     * Registra um novo evento.
     */
    public static function log(
        string $type,
        array $payload = [],
        ?int $screenId = null,
        ?int $campaignId = null
    ): self {
        return static::create([
            'event_id' => (string) Str::uuid(),
            'type' => $type,
            'screen_id' => $screenId,
            'campaign_id' => $campaignId,
            'payload' => $payload,
        ]);
    }

    /**
     * Log de conexão de screen.
     */
    public static function logScreenConnected(WheelScreen $screen, array $deviceInfo = []): self
    {
        return static::log(
            self::TYPE_SCREEN_CONNECTED,
            ['device_info' => $deviceInfo],
            $screen->id
        );
    }

    /**
     * Log de ativação de campanha.
     */
    public static function logCampaignActivated(WheelCampaign $campaign, ?int $screenId = null): self
    {
        return static::log(
            self::TYPE_CAMPAIGN_ACTIVATED,
            ['campaign_key' => $campaign->campaign_key],
            $screenId,
            $campaign->id
        );
    }

    /**
     * Log de prêmio ganho.
     */
    public static function logPrizeWon(
        WheelCampaign $campaign,
        WheelPrize $prize,
        int $screenId,
        array $extra = []
    ): self {
        return static::log(
            self::TYPE_PRIZE_WON,
            array_merge([
                'prize_key' => $prize->prize_key,
                'prize_name' => $prize->name,
                'prize_type' => $prize->type->value,
            ], $extra),
            $screenId,
            $campaign->id
        );
    }

    /**
     * Log de mudança de configuração.
     */
    public static function logConfigChanged(
        WheelCampaign $campaign,
        string $what,
        array $changes = []
    ): self {
        return static::log(
            self::TYPE_CONFIG_CHANGED,
            [
                'what' => $what,
                'changes' => $changes,
            ],
            null,
            $campaign->id
        );
    }
}
