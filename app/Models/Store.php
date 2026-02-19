<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Store extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'name',
        'codigo',
        'city',
        'active',
        'photo_url',
        'address',
        'neighborhood',
        'state',
        'zip_code',
        'latitude',
        'longitude',
        'phone',
        'whatsapp',
        'instagram',
        'bio_enabled',
        'opening_hours',
        'cnpj',
        'troco_padrao',
        'guid',
        'razao_social',
        'nome_fantasia',
    ];

    protected $casts = [
        'active' => 'boolean',
        'bio_enabled' => 'boolean',
        'opening_hours' => 'array',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'troco_padrao' => 'decimal:2',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function storeUsers(): HasMany
    {
        return $this->hasMany(StoreUser::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'store_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function cashShifts(): HasMany
    {
        return $this->hasMany(CashShift::class);
    }

    public function pdvTurnos(): HasMany
    {
        return $this->hasMany(PdvTurno::class, 'store_id');
    }

    public function targetsDaily(): HasMany
    {
        return $this->hasMany(TargetDaily::class);
    }

    public function targetsMonthly(): HasMany
    {
        return $this->hasMany(TargetMonthly::class);
    }

    public function bonusRules(): HasMany
    {
        return $this->hasMany(BonusRule::class);
    }

    public function commissionRules(): HasMany
    {
        return $this->hasMany(CommissionRule::class);
    }

    public function modules()
    {
        return $this->belongsToMany(Module::class, 'module_store')
            ->withPivot('is_active', 'config')
            ->withTimestamps();
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeBioEnabled($query)
    {
        return $query->where('bio_enabled', true);
    }

    // ========================================
    // Activity Log
    // ========================================

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'city', 'active'])
            ->logOnlyDirty();
    }
}
