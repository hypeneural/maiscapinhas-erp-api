<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScreenStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Model: WheelScreen
 * 
 * Representa uma TV/Totem que exibe a roleta na vitrine.
 */
class WheelScreen extends Model
{
    use HasFactory;

    protected $table = 'wheel_screens';

    protected $fillable = [
        'screen_key',
        'store_id',
        'name',
        'secret_token_hash',
        'status',
        'device_info',
        'last_seen_at',
    ];

    protected $casts = [
        'status' => ScreenStatus::class,
        'device_info' => 'array',
        'last_seen_at' => 'datetime',
    ];

    protected $hidden = [
        'secret_token_hash',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($screen) {
            // Gerar screen_key automaticamente se não fornecido
            if (empty($screen->screen_key)) {
                $screen->screen_key = 'screen-' . Str::random(12);
            }
        });
    }

    // ========================================
    // Relationships
    // ========================================

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(WheelCampaign::class, 'wheel_screen_campaign', 'screen_id', 'campaign_id')
            ->withPivot('status')
            ->withTimestamps();
    }

    public function activeCampaigns(): BelongsToMany
    {
        return $this->campaigns()->wherePivot('status', 'active');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WheelEvent::class, 'screen_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeActive($query)
    {
        return $query->where('status', ScreenStatus::ACTIVE);
    }

    public function scopeByStore($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeOnline($query, int $minutes = 5)
    {
        return $query->where('last_seen_at', '>=', now()->subMinutes($minutes));
    }

    public function scopeOffline($query, int $minutes = 5)
    {
        return $query->where(function ($q) use ($minutes) {
            $q->whereNull('last_seen_at')
                ->orWhere('last_seen_at', '<', now()->subMinutes($minutes));
        });
    }

    // ========================================
    // Methods
    // ========================================

    /**
     * Gera um novo secret token e retorna o valor em texto plano (apenas 1x).
     */
    public function rotateSecretToken(): string
    {
        $plainToken = Str::random(64);
        $this->secret_token_hash = Hash::make($plainToken);
        $this->save();

        return $plainToken;
    }

    /**
     * Verifica se o token fornecido é válido.
     */
    public function verifySecretToken(string $token): bool
    {
        if (!$this->secret_token_hash) {
            return false;
        }

        return Hash::check($token, $this->secret_token_hash);
    }

    /**
     * Atualiza o heartbeat da TV.
     */
    public function heartbeat(array $deviceInfo = []): void
    {
        $this->last_seen_at = now();

        if (!empty($deviceInfo)) {
            $this->device_info = array_merge($this->device_info ?? [], $deviceInfo);
        }

        $this->save();
    }

    /**
     * Verifica se a TV está online (última comunicação recente).
     */
    public function isOnline(int $minutes = 5): bool
    {
        if (!$this->last_seen_at) {
            return false;
        }

        return $this->last_seen_at->gte(now()->subMinutes($minutes));
    }

    /**
     * Retorna a campanha ativa atual.
     */
    public function getActiveCampaign(): ?WheelCampaign
    {
        return $this->activeCampaigns()->first();
    }

    /**
     * Gera screen_key automaticamente se não fornecido.
     */
    public static function generateScreenKey(Store $store, ?string $suffix = null): string
    {
        $storeName = Str::slug($store->name, '-');
        $suffix = $suffix ?? Str::random(4);

        return "screen-{$storeName}-{$suffix}";
    }
}
