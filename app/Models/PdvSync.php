<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PdvSync extends Model
{
    use HasFactory;

    protected $fillable = [
        'sync_id',
        'schema_version',
        'event_type',
        'request_id',
        'store_pdv_id',
        'store_id',
        'store_alias',
        'window_from',
        'window_to',
        'agent_version',
        'agent_machine',
        'ops_count',
        'ops_loja_count',
        'ops_loja_ids',
        'snapshot_turnos_count',
        'snapshot_vendas_count',
        'warnings',
        'status',
        'timestamp_skew_seconds',
        'timestamp_out_of_window',
        'risk_flags',
        'payload_sha256',
        'payload_bytes',
        'store_id_filial',
        'attempts',
        'last_error',
        'received_at',
        'queued_at',
        'processing_started_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'window_from' => 'datetime',
            'window_to' => 'datetime',
            'ops_loja_ids' => 'array',
            'snapshot_turnos_count' => 'integer',
            'snapshot_vendas_count' => 'integer',
            'warnings' => 'array',
            'timestamp_out_of_window' => 'boolean',
            'risk_flags' => 'array',
            'received_at' => 'datetime',
            'queued_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public const STATUS_RECEIVED = 'received';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BLOCKED = 'blocked';

    public const EVENT_TYPE_SALES = 'sales';
    public const EVENT_TYPE_TURNO_CLOSURE = 'turno_closure';
    public const EVENT_TYPE_MIXED = 'mixed';

    public function payload(): HasOne
    {
        return $this->hasOne(PdvSyncPayload::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
