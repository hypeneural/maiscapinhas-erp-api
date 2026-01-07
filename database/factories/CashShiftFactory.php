<?php

namespace Database\Factories;

use App\Models\CashShift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CashShiftFactory extends Factory
{
    protected $model = CashShift::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'date' => fake()->dateTimeBetween('-14 days', 'now')->format('Y-m-d'),
            'shift_code' => fake()->randomElement(['M', 'T', 'N']),
            'seller_id' => User::factory(),
            'status' => CashShift::STATUS_OPEN,
        ];
    }

    public function forStore(Store $store): static
    {
        return $this->state(fn(array $attributes) => [
            'store_id' => $store->id,
        ]);
    }

    public function forSeller(User $seller): static
    {
        return $this->state(fn(array $attributes) => [
            'seller_id' => $seller->id,
        ]);
    }

    public function onDate($date): static
    {
        return $this->state(fn(array $attributes) => [
            'date' => $date,
        ]);
    }

    public function morning(): static
    {
        return $this->state(fn(array $attributes) => [
            'shift_code' => CashShift::SHIFT_MORNING,
        ]);
    }

    public function afternoon(): static
    {
        return $this->state(fn(array $attributes) => [
            'shift_code' => CashShift::SHIFT_AFTERNOON,
        ]);
    }

    public function night(): static
    {
        return $this->state(fn(array $attributes) => [
            'shift_code' => CashShift::SHIFT_NIGHT,
        ]);
    }

    public function open(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => CashShift::STATUS_OPEN,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => CashShift::STATUS_CLOSED,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => CashShift::STATUS_PENDING,
        ]);
    }
}
