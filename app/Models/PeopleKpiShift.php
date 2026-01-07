<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KpiSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeopleKpiShift extends Model
{
    use HasFactory;

    protected $table = 'people_kpi_shift';

    protected $fillable = [
        'store_id',
        'date',
        'shift_code',
        'in_count',
        'out_count',
        'staff_in',
        'staff_out',
        'source',
        'raw_json',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'in_count' => 'integer',
            'out_count' => 'integer',
            'staff_in' => 'integer',
            'staff_out' => 'integer',
            'source' => KpiSource::class,
            'raw_json' => 'array',
        ];
    }

    // ========================================
    // Relationships
    // ========================================

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeForStore($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('date', $date);
    }

    public function scopeForShift($query, string $shiftCode)
    {
        return $query->where('shift_code', $shiftCode);
    }

    public function scopeFromFastApi($query)
    {
        return $query->where('source', KpiSource::FASTAPI);
    }

    // ========================================
    // Helpers
    // ========================================

    public function getConversionRate(): float
    {
        if ($this->in_count === 0) {
            return 0;
        }

        return round(($this->out_count / $this->in_count) * 100, 2);
    }

    public function getShiftLabel(): string
    {
        return match ($this->shift_code) {
            'M' => 'Manhã',
            'T' => 'Tarde',
            'N' => 'Noite',
            default => $this->shift_code,
        };
    }

    // ========================================
    // Static Helpers
    // ========================================

    public static function getDayTotals(int $storeId, $date): array
    {
        $shifts = self::forStore($storeId)->forDate($date)->get();

        return [
            'in_count' => $shifts->sum('in_count'),
            'out_count' => $shifts->sum('out_count'),
            'staff_in' => $shifts->sum('staff_in'),
            'staff_out' => $shifts->sum('staff_out'),
            'conversion_rate' => $shifts->sum('in_count') > 0
                ? round(($shifts->sum('out_count') / $shifts->sum('in_count')) * 100, 2)
                : 0,
        ];
    }
}
