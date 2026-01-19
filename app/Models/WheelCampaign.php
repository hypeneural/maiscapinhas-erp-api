<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CampaignStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Model: WheelCampaign
 * 
 * Campanha da roleta com configurações de duração, termos e limites.
 */
class WheelCampaign extends Model
{
    use HasFactory;

    protected $table = 'wheel_campaigns';

    protected $fillable = [
        'campaign_key',
        'name',
        'status',
        'starts_at',
        'ends_at',
        'terms_version',
        'settings',
    ];

    protected $casts = [
        'status' => CampaignStatus::class,
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'settings' => 'array',
    ];

    /**
     * Configurações padrão da campanha.
     */
    public const DEFAULT_SETTINGS = [
        'qr_ttl_seconds' => 120,
        'spin_duration_ms' => 8000,
        'min_rotations' => 5,
        'max_rotations' => 8,
        'max_queue_size' => 10,
        'per_phone_limit' => '1_per_campaign',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function screens(): BelongsToMany
    {
        return $this->belongsToMany(WheelScreen::class, 'wheel_screen_campaign', 'campaign_id', 'screen_id')
            ->withPivot('status')
            ->withTimestamps();
    }

    public function activeScreens(): BelongsToMany
    {
        return $this->screens()->wherePivot('status', 'active');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(WheelSegment::class, 'campaign_id')
            ->orderBy('sort_order');
    }

    public function activeSegments(): HasMany
    {
        return $this->segments()->where('active', true);
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(WheelInventory::class, 'campaign_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WheelEvent::class, 'campaign_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeActive($query)
    {
        return $query->where('status', CampaignStatus::ACTIVE);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', CampaignStatus::DRAFT);
    }

    public function scopeRunning($query)
    {
        return $query->where('status', CampaignStatus::ACTIVE)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            });
    }

    // ========================================
    // Methods
    // ========================================

    /**
     * Retorna configuração com fallback para defaults.
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        $settings = $this->settings ?? [];

        return $settings[$key] ?? self::DEFAULT_SETTINGS[$key] ?? $default;
    }

    /**
     * Atualiza uma configuração específica.
     */
    public function setSetting(string $key, mixed $value): void
    {
        $settings = $this->settings ?? [];
        $settings[$key] = $value;
        $this->settings = $settings;
    }

    /**
     * Verifica se a campanha está no período válido.
     */
    public function isWithinPeriod(): bool
    {
        $now = now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at && $now->gt($this->ends_at)) {
            return false;
        }

        return true;
    }

    /**
     * Verifica se a campanha pode ser ativada.
     */
    public function canActivate(): bool
    {
        // Status deve permitir ativação
        if (!$this->status->canActivate()) {
            return false;
        }

        // Deve ter pelo menos um segmento ativo
        if ($this->activeSegments()->count() === 0) {
            return false;
        }

        // Todos os segmentos devem ter prize_id válido e weight >= 1
        $invalidSegments = $this->activeSegments()
            ->where(function ($q) {
                $q->where('probability_weight', '<', 1)
                    ->orWhereHas('prize', fn($p) => $p->where('active', false));
            })
            ->exists();

        if ($invalidSegments) {
            return false;
        }

        return true;
    }

    /**
     * Ativa a campanha.
     */
    public function activate(): bool
    {
        if (!$this->canActivate()) {
            return false;
        }

        $this->status = CampaignStatus::ACTIVE;
        return $this->save();
    }

    /**
     * Pausa a campanha.
     */
    public function pause(): bool
    {
        if (!$this->status->canPause()) {
            return false;
        }

        $this->status = CampaignStatus::PAUSED;
        return $this->save();
    }

    /**
     * Encerra a campanha.
     */
    public function end(): bool
    {
        if (!$this->status->canEnd()) {
            return false;
        }

        $this->status = CampaignStatus::ENDED;
        return $this->save();
    }

    /**
     * Gera campaign_key automaticamente.
     */
    public static function generateCampaignKey(?string $prefix = null): string
    {
        $prefix = $prefix ?? 'camp';
        $date = now()->format('Y_m');
        $suffix = Str::random(4);

        return "{$prefix}_{$date}_{$suffix}";
    }

    /**
     * Retorna a soma total dos pesos de probabilidade.
     */
    public function getTotalWeight(): int
    {
        return (int) $this->activeSegments()->sum('probability_weight');
    }
}
