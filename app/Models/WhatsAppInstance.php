<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsAppInstance extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'whatsapp_instances';

    protected $fillable = [
        'store_id',
        'user_id',
        'provider',
        'name',
        'phone_e164',
        'base_url',
        'is_default',
        'is_active',
        'notes',
        'api_key',
        'api_key_last4',
        'api_key_fingerprint',
        'token',
        'token_last4',
        'token_fingerprint',
        'status',
        'last_state',
        'last_state_checked_at',
        'webhook_secret',
        'webhook_url',
        'webhook_events',
    ];

    /**
     * Secrets that should never be serialized.
     */
    protected $hidden = [
        'api_key',
        'token',
        'webhook_secret',
    ];

    /**
     * Appended attributes for API responses.
     */
    protected $appends = [
        'has_api_key',
        'has_token',
        'api_key_masked',
        'token_masked',
        'scope',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            // api_key and token encrypted via mutators
            'webhook_secret' => 'encrypted',
            'last_state' => 'array',
            'webhook_events' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'last_state_checked_at' => 'datetime',
        ];
    }

    // ========================================
    // Relationships
    // ========================================

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeGlobal($query)
    {
        return $query->whereNull('store_id')->whereNull('user_id');
    }

    public function scopeForStore($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    // ========================================
    // Accessors (appended attributes)
    // ========================================

    public function getHasApiKeyAttribute(): bool
    {
        return !empty($this->api_key_last4) || !empty($this->api_key_fingerprint);
    }

    public function getHasTokenAttribute(): bool
    {
        return !empty($this->token_last4) || !empty($this->token_fingerprint);
    }

    public function getApiKeyMaskedAttribute(): ?string
    {
        return $this->api_key_last4 ? str_repeat('*', 8) . $this->api_key_last4 : null;
    }

    public function getTokenMaskedAttribute(): ?string
    {
        return $this->token_last4 ? str_repeat('*', 8) . $this->token_last4 : null;
    }

    public function getScopeAttribute(): string
    {
        if (!empty($this->user_id)) {
            return 'user';
        }
        if (!empty($this->store_id)) {
            return 'store';
        }
        return 'global';
    }

    // ========================================
    // Getters (decrypt secrets)
    // ========================================

    public function getApiKeyAttribute(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return decrypt($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getTokenAttribute(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return decrypt($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    // ========================================
    // Mutators (set secrets with metadata)
    // ========================================

    public function setApiKeyAttribute(?string $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['api_key'] = null;
            $this->attributes['api_key_last4'] = null;
            $this->attributes['api_key_fingerprint'] = null;
        } else {
            // Encrypt manually since we're setting attributes directly (bypasses cast)
            $this->attributes['api_key'] = encrypt($value);
            $this->attributes['api_key_last4'] = substr($value, -4);
            $this->attributes['api_key_fingerprint'] = substr(hash('sha256', $value), 0, 16);
        }
    }

    public function setTokenAttribute(?string $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['token'] = null;
            $this->attributes['token_last4'] = null;
            $this->attributes['token_fingerprint'] = null;
        } else {
            // Encrypt manually since we're setting attributes directly
            $this->attributes['token'] = encrypt($value);
            $this->attributes['token_last4'] = substr($value, -4);
            $this->attributes['token_fingerprint'] = substr(hash('sha256', $value), 0, 16);
        }
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * Check if the instance is connected.
     */
    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    /**
     * Map Evolution API state to internal status.
     */
    public static function mapEvolutionState(string $state): string
    {
        return match ($state) {
            'open' => 'connected',
            'close' => 'disconnected',
            'connecting' => 'connecting',
            default => 'unknown',
        };
    }

    /**
     * Get the default instance for a given scope.
     * Resolution order: user -> store -> global
     */
    public static function resolveForContext(?int $userId, ?int $storeId): ?self
    {
        // Try user-specific instance first
        if ($userId) {
            $instance = static::forUser($userId)->active()->default()->first();
            if ($instance) {
                return $instance;
            }
        }

        // Try store-specific instance
        if ($storeId) {
            $instance = static::forStore($storeId)->active()->default()->first();
            if ($instance) {
                return $instance;
            }
        }

        // Fall back to global default
        return static::global()->active()->default()->first();
    }
}
