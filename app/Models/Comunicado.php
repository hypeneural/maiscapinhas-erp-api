<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comunicado extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'comunicados';

    protected $fillable = [
        'title',
        'description',
        'status',
        'created_by_id',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeByStatus($query, ?int $status)
    {
        if ($status !== null) {
            return $query->where('status', $status);
        }
        return $query;
    }
}