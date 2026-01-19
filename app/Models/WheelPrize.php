<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PrizeType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Model: WheelPrize
 * 
 * Prêmio disponível na roleta.
 */
class WheelPrize extends Model
{
    use HasFactory;

    protected $table = 'wheel_prizes';

    protected $fillable = [
        'prize_key',
        'name',
        'type',
        'icon',
        'description',
        'redeem_instructions',
        'code_prefix',
        'active',
    ];

    protected $casts = [
        'type' => PrizeType::class,
        'active' => 'boolean',
    ];

    protected $attributes = [
        'active' => true,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($prize) {
            // Gerar prize_key automaticamente se não fornecido
            if (empty($prize->prize_key)) {
                $prize->prize_key = 'prize_' . Str::random(8);
            }
        });
    }

    // ========================================
    // Relationships
    // ========================================

    public function segments(): HasMany
    {
        return $this->hasMany(WheelSegment::class, 'prize_id');
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(WheelInventory::class, 'prize_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeByType($query, PrizeType $type)
    {
        return $query->where('type', $type);
    }

    public function scopeRedeemable($query)
    {
        return $query->whereIn('type', [PrizeType::PRODUCT->value, PrizeType::COUPON->value]);
    }

    // ========================================
    // Methods
    // ========================================

    /**
     * Verifica se o prêmio requer resgate.
     */
    public function requiresRedeem(): bool
    {
        return $this->type->requiresRedeem();
    }

    /**
     * Verifica se o prêmio consome inventário.
     */
    public function consumesInventory(): bool
    {
        return $this->type->consumesInventory();
    }

    /**
     * Gera um código de prêmio.
     */
    public function generateCode(): string
    {
        $prefix = $this->code_prefix ?? 'MC';
        $code = strtoupper(Str::random(6));

        return "{$prefix}-{$code}";
    }

    /**
     * Toggle ativo/inativo.
     */
    public function toggle(): bool
    {
        $this->active = !$this->active;
        return $this->save();
    }

    /**
     * Gera prize_key automaticamente.
     */
    public static function generatePrizeKey(string $name): string
    {
        return Str::slug($name, '_');
    }
}
