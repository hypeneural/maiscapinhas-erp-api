<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model: WheelScreenCampaign
 * 
 * Pivot entre Screen e Campaign com status do vínculo.
 */
class WheelScreenCampaign extends Model
{
    protected $table = 'wheel_screen_campaign';

    protected $fillable = [
        'screen_id',
        'campaign_id',
        'status',
    ];

    // ========================================
    // Relationships
    // ========================================

    public function screen(): BelongsTo
    {
        return $this->belongsTo(WheelScreen::class, 'screen_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WheelCampaign::class, 'campaign_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }
}
