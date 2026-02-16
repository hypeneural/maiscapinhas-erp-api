<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HiperEndpoint extends Model
{
    protected $fillable = [
        'key',
        'method',
        'path',
        'headers',
        'query_template',
        'body_template',
    ];

    protected $casts = [
        'headers' => 'array',
        'query_template' => 'array',
        'body_template' => 'array',
    ];

    /**
     * Use 'key' for route model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'key';
    }
}
