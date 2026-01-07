<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

class StoreFactory extends Factory
{
    protected $model = Store::class;

    public function definition(): array
    {
        return [
            'name' => 'Loja ' . fake()->city(),
            'city' => fake()->city(),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn(array $attributes) => [
            'active' => false,
        ]);
    }

    // Predefined stores for seeding
    public static function tijucas(): array
    {
        return [
            'name' => 'Mais Capinhas Tijucas',
            'city' => 'Tijucas',
            'active' => true,
        ];
    }

    public static function itapema(): array
    {
        return [
            'name' => 'Mais Capinhas Itapema',
            'city' => 'Itapema',
            'active' => true,
        ];
    }

    public static function bombinhas(): array
    {
        return [
            'name' => 'Mais Capinhas Bombinhas',
            'city' => 'Bombinhas',
            'active' => true,
        ];
    }
}
