<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Model: WheelSegment
 * 
 * Segmento (fatia) da roleta vinculado a uma campanha e prêmio.
 */
class WheelSegment extends Model
{
    use HasFactory;

    protected $table = 'wheel_segments';

    protected $fillable = [
        'campaign_id',
        'segment_key',
        'label',
        'color',
        'prize_id',
        'probability_weight',
        'sort_order',
        'active',
    ];

    protected $casts = [
        'probability_weight' => 'integer',
        'sort_order' => 'integer',
        'active' => 'boolean',
    ];

    protected $attributes = [
        'active' => true,
        'probability_weight' => 1,
        'sort_order' => 0,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($segment) {
            // Gerar segment_key automaticamente se não fornecido
            if (empty($segment->segment_key)) {
                $segment->segment_key = 'seg_' . Str::random(8);
            }
        });
    }

    // ========================================
    // Relationships
    // ========================================

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WheelCampaign::class, 'campaign_id');
    }

    public function prize(): BelongsTo
    {
        return $this->belongsTo(WheelPrize::class, 'prize_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    public function scopeByCampaign($query, int $campaignId)
    {
        return $query->where('campaign_id', $campaignId);
    }

    // ========================================
    // Methods
    // ========================================

    /**
     * Calcula a probabilidade do segmento em relação ao total.
     */
    public function getProbabilityPercentage(): float
    {
        $totalWeight = $this->campaign->getTotalWeight();

        if ($totalWeight === 0) {
            return 0;
        }

        return round(($this->probability_weight / $totalWeight) * 100, 2);
    }

    /**
     * Gera segment_key automaticamente.
     */
    public static function generateSegmentKey(WheelCampaign $campaign, string $label): string
    {
        $campaignPrefix = Str::slug($campaign->name, '_');
        $labelSlug = Str::slug($label, '_');
        $suffix = Str::random(4);

        return "seg_{$labelSlug}_{$suffix}";
    }

    /**
     * Reordena os segmentos de uma campanha.
     */
    public static function reorderForCampaign(int $campaignId, array $segmentIds): void
    {
        foreach ($segmentIds as $index => $segmentId) {
            static::where('id', $segmentId)
                ->where('campaign_id', $campaignId)
                ->update(['sort_order' => $index]);
        }
    }
}
