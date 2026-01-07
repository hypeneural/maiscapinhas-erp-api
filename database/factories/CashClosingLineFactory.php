<?php

namespace Database\Factories;

use App\Models\CashClosing;
use App\Models\CashClosingLine;
use Illuminate\Database\Eloquent\Factories\Factory;

class CashClosingLineFactory extends Factory
{
    protected $model = CashClosingLine::class;

    public function definition(): array
    {
        $systemValue = fake()->randomFloat(2, 100, 5000);
        $realValue = $systemValue + fake()->randomFloat(2, -50, 50);

        return [
            'cash_closing_id' => CashClosing::factory(),
            'label' => fake()->randomElement(CashClosingLine::LABELS),
            'system_value' => $systemValue,
            'real_value' => $realValue,
            'diff_value' => $realValue - $systemValue,
            'justification_text' => null,
        ];
    }

    public function forClosing(CashClosing $closing): static
    {
        return $this->state(fn(array $attributes) => [
            'cash_closing_id' => $closing->id,
        ]);
    }

    public function cash(): static
    {
        return $this->state(fn(array $attributes) => [
            'label' => CashClosingLine::LABEL_CASH,
        ]);
    }

    public function creditCard(): static
    {
        return $this->state(fn(array $attributes) => [
            'label' => CashClosingLine::LABEL_CREDIT_CARD,
        ]);
    }

    public function debitCard(): static
    {
        return $this->state(fn(array $attributes) => [
            'label' => CashClosingLine::LABEL_DEBIT_CARD,
        ]);
    }

    public function pix(): static
    {
        return $this->state(fn(array $attributes) => [
            'label' => CashClosingLine::LABEL_PIX,
        ]);
    }

    public function withDivergence(float $diff = 50.00): static
    {
        return $this->state(function (array $attributes) use ($diff) {
            $systemValue = $attributes['system_value'] ?? 1000.00;
            return [
                'real_value' => $systemValue + $diff,
                'diff_value' => $diff,
            ];
        });
    }

    public function noDivergence(): static
    {
        return $this->state(function (array $attributes) {
            $systemValue = $attributes['system_value'] ?? 1000.00;
            return [
                'real_value' => $systemValue,
                'diff_value' => 0,
            ];
        });
    }

    public function justified(string $text = 'Divergência justificada pelo operador'): static
    {
        return $this->state(fn(array $attributes) => [
            'justification_text' => $text,
        ]);
    }
}
