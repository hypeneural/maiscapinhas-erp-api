<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'seller_id' => User::factory(),
            'sold_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'amount' => fake()->randomFloat(2, 15, 500),
            'source' => fake()->randomElement(Sale::SOURCES),
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
            'sold_at' => $date,
        ]);
    }

    public function pdv(): static
    {
        return $this->state(fn(array $attributes) => [
            'source' => Sale::SOURCE_PDV,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn(array $attributes) => [
            'source' => Sale::SOURCE_MANUAL,
        ]);
    }
}
