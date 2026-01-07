<?php

declare(strict_types=1);

namespace App\Enums;

enum CashClosingStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match($this) {
            self::DRAFT => 'Rascunho',
            self::SUBMITTED => 'Enviado',
            self::APPROVED => 'Aprovado',
            self::REJECTED => 'Rejeitado',
        };
    }

    public function canSubmit(): bool
    {
        return $this === self::DRAFT || $this === self::REJECTED;
    }

    public function canApprove(): bool
    {
        return $this === self::SUBMITTED;
    }

    public function canReject(): bool
    {
        return $this === self::SUBMITTED;
    }

    public function isFinalized(): bool
    {
        return $this === self::APPROVED;
    }
}
