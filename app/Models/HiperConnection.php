<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HiperConnection extends Model
{
    protected $fillable = [
        'name',
        'base_url',
        'default_referer',
        'default_headers',
        'cookies',
        'is_active',
        'last_used_at',
    ];

    protected $casts = [
        'default_headers' => 'array',
        'cookies' => 'encrypted:array',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    protected $hidden = [
        'cookies',
    ];
}
