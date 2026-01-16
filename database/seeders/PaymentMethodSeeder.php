<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $paymentMethods = [
            [
                'name' => 'Dinheiro',
                'slug' => 'dinheiro',
                'description' => 'Pagamento em espécie',
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Pix',
                'slug' => 'pix',
                'description' => 'Pagamento instantâneo via Pix',
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Débito',
                'slug' => 'debito',
                'description' => 'Pagamento com cartão de débito',
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'Crédito',
                'slug' => 'credito',
                'description' => 'Pagamento com cartão de crédito',
                'is_active' => true,
                'sort_order' => 4,
            ],
        ];

        foreach ($paymentMethods as $method) {
            PaymentMethod::updateOrCreate(
                ['slug' => $method['slug']],
                $method
            );
        }
    }
}
