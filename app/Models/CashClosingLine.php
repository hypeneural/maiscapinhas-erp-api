<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CashClosingLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_closing_id',
        'label',
        'system_value',
        'real_value',
        'diff_value',
        'justification_text',
    ];

    protected $casts = [
        'system_value' => 'decimal:2',
        'real_value' => 'decimal:2',
        'diff_value' => 'decimal:2',
    ];

    // Common payment method labels
    public const LABEL_CASH = 'Dinheiro';
    public const LABEL_CREDIT_CARD = 'Cartão Crédito';
    public const LABEL_DEBIT_CARD = 'Cartão Débito';
    public const LABEL_PIX = 'PIX';
    public const LABEL_OTHER = 'Outros';

    public const LABELS = [
        self::LABEL_CASH,
        self::LABEL_CREDIT_CARD,
        self::LABEL_DEBIT_CARD,
        self::LABEL_PIX,
        self::LABEL_OTHER,
    ];

    // ========================================
    // Relationships
    // ========================================

    public function cashClosing(): BelongsTo
    {
        return $this->belongsTo(CashClosing::class);
    }

    public function divergence(): HasOne
    {
        return $this->hasOne(Divergence::class);
    }

    // ========================================
    // Helpers
    // ========================================

    public function hasDivergence(): bool
    {
        return bccomp((string) $this->diff_value, '0', 2) !== 0;
    }

    public function isJustified(): bool
    {
        return !empty($this->justification_text);
    }

    public function needsJustification(): bool
    {
        return $this->hasDivergence() && !$this->isJustified();
    }

    public function calculateDiff(): void
    {
        $this->diff_value = bcsub((string) $this->real_value, (string) $this->system_value, 2);
    }
}
