<?php

namespace Database\Factories;

use App\Models\CashClosing;
use App\Models\CashShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CashClosingFactory extends Factory
{
    protected $model = CashClosing::class;

    public function definition(): array
    {
        return [
            'cash_shift_id' => CashShift::factory(),
            'status' => CashClosing::STATUS_DRAFT,
            'closed_by' => null,
            'closed_at' => null,
            'version' => 1,
        ];
    }

    public function forShift(CashShift $shift): static
    {
        return $this->state(fn(array $attributes) => [
            'cash_shift_id' => $shift->id,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => CashClosing::STATUS_DRAFT,
        ]);
    }

    public function submitted(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => CashClosing::STATUS_SUBMITTED,
        ]);
    }

    public function approved(User $approver = null): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => CashClosing::STATUS_APPROVED,
            'closed_by' => $approver?->id,
            'closed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => CashClosing::STATUS_REJECTED,
        ]);
    }
}
