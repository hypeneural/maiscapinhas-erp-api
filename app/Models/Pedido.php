<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PedidoStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pedido extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'store_id',
        'user_id',
        'customer_id',
        'customer_device_id',
        'selected_product',
        'obs',
        'status',
        'created_by_id',
        'updated_by_id',
    ];

    protected $casts = [
        'status' => PedidoStatus::class,
    ];

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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerDevice(): BelongsTo
    {
        return $this->belongsTo(CustomerDevice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(PedidoStatusHistory::class)->orderBy('changed_at', 'desc');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeForStore($query, ?int $storeId)
    {
        if (!$storeId) {
            return $query;
        }
        return $query->where('store_id', $storeId);
    }

    public function scopeForUser($query, ?int $userId)
    {
        if (!$userId) {
            return $query;
        }
        return $query->where('user_id', $userId);
    }

    public function scopeWithStatus($query, $status)
    {
        if ($status === null) {
            return $query;
        }
        return $query->where('status', $status);
    }

    public function scopeForCustomer($query, ?int $customerId)
    {
        if (!$customerId) {
            return $query;
        }
        return $query->where('customer_id', $customerId);
    }

    public function scopeCreatedBetween($query, ?string $from, ?string $to)
    {
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }
        return $query;
    }

    public function scopeSearch($query, ?string $term)
    {
        if (!$term) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('selected_product', 'like', "%{$term}%")
                ->orWhere('obs', 'like', "%{$term}%")
                ->orWhereHas('customer', function ($cq) use ($term) {
                    $cq->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%");
                });
        });
    }

    public function scopeByDeviceBrand($query, ?int $brandId)
    {
        if (!$brandId) {
            return $query;
        }

        return $query->whereHas('customerDevice.phoneModel', function ($q) use ($brandId) {
            $q->where('brand_id', $brandId);
        });
    }

    public function scopeByDeviceModel($query, ?int $modelId)
    {
        if (!$modelId) {
            return $query;
        }

        return $query->whereHas('customerDevice', function ($q) use ($modelId) {
            $q->where('phone_model_id', $modelId);
        });
    }
}
