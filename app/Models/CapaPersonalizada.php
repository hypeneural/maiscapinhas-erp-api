<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CapaPersonalizadaStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CapaPersonalizada extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'capas_personalizadas';

    protected $fillable = [
        'store_id',
        'user_id',
        'customer_id',
        'customer_device_id',
        'selected_product',
        'product_reference',
        'obs',
        'photo_path',
        'upload_token',
        'upload_token_expires_at',
        'qty',
        'price',
        'payed',
        'payday',
        'received_by_id',
        'sended_to_production_at',
        'status',
        'created_by_id',
        'updated_by_id',
    ];

    protected $casts = [
        'qty' => 'integer',
        'price' => 'decimal:2',
        'payed' => 'boolean',
        'payday' => 'date',
        'sended_to_production_at' => 'date',
        'upload_token_expires_at' => 'datetime',
        'status' => CapaPersonalizadaStatus::class,
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

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
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

    public function scopePayed($query, ?bool $payed)
    {
        if ($payed === null) {
            return $query;
        }
        return $query->where('payed', $payed);
    }

    public function scopePayday($query, ?string $payday)
    {
        if (!$payday) {
            return $query;
        }
        return $query->whereDate('payday', $payday);
    }

    public function scopeReceivedBy($query, ?int $receivedById)
    {
        if (!$receivedById) {
            return $query;
        }
        return $query->where('received_by_id', $receivedById);
    }

    public function scopeSearch($query, ?string $term)
    {
        if (!$term) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('selected_product', 'like', "%{$term}%")
                ->orWhere('product_reference', 'like', "%{$term}%")
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

    // ========================================
    // Accessors
    // ========================================

    public function getPhotoUrlAttribute(): ?string
    {
        if (!$this->photo_path) {
            return null;
        }
        return asset('storage/' . $this->photo_path);
    }

    public function getTotalAttribute(): ?float
    {
        if ($this->price === null) {
            return null;
        }
        return (float) $this->price * $this->qty;
    }

    // ========================================
    // Upload Token Methods
    // ========================================

    /**
     * Check if the provided token is valid and not expired.
     */
    public function hasValidUploadToken(string $token): bool
    {
        if (!$this->upload_token || !$this->upload_token_expires_at) {
            return false;
        }

        return $this->upload_token === $token
            && $this->upload_token_expires_at->isFuture();
    }

    /**
     * Clear the upload token after successful upload.
     */
    public function clearUploadToken(): void
    {
        $this->update([
            'upload_token' => null,
            'upload_token_expires_at' => null,
        ]);
    }
}
