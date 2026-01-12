<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PedidoStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoStatusHistory extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'pedido_status_history';

    protected $fillable = [
        'pedido_id',
        'old_status',
        'new_status',
        'changed_by_id',
        'changed_at',
        'source',
        'reason',
        'meta_json',
    ];

    protected $casts = [
        'old_status' => PedidoStatus::class,
        'new_status' => PedidoStatus::class,
        'changed_at' => 'datetime',
        'meta_json' => 'array',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }
}
