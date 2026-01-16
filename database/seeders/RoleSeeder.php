<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'name' => Role::SUPER_ADMIN,
                'display_name' => 'Super Administrador',
                'description' => 'Acesso total ao sistema. Pode gerenciar todas as lojas e usuários.',
                'level' => Role::LEVEL_SUPER_ADMIN,
                'is_system' => true,
            ],
            [
                'name' => Role::ADMIN,
                'display_name' => 'Administrador',
                'description' => 'Administrador de lojas. Pode gerenciar usuários e configurações.',
                'level' => Role::LEVEL_ADMIN,
                'is_system' => true,
            ],
            [
                'name' => Role::FABRICA,
                'display_name' => 'Fábrica',
                'description' => 'Usuário da fábrica. Acesso ao portal de produção.',
                'level' => Role::LEVEL_FABRICA,
                'is_system' => true,
            ],
            [
                'name' => Role::GERENTE,
                'display_name' => 'Gerente',
                'description' => 'Gerente de loja. Pode aprovar fechamentos e ver relatórios.',
                'level' => Role::LEVEL_GERENTE,
                'is_system' => true,
            ],
            [
                'name' => Role::CONFERENTE,
                'display_name' => 'Conferente',
                'description' => 'Conferente de caixa. Pode aprovar fechamentos.',
                'level' => Role::LEVEL_CONFERENTE,
                'is_system' => true,
            ],
            [
                'name' => Role::ESTOQUISTA,
                'display_name' => 'Estoquista',
                'description' => 'Responsável pelo controle de estoque.',
                'level' => Role::LEVEL_ESTOQUISTA,
                'is_system' => true,
            ],
            [
                'name' => Role::VENDEDOR,
                'display_name' => 'Vendedor',
                'description' => 'Vendedor padrão. Registra vendas e pedidos.',
                'level' => Role::LEVEL_VENDEDOR,
                'is_system' => true,
            ],
        ];

        foreach ($roles as $roleData) {
            Role::updateOrCreate(
                ['name' => $roleData['name']],
                $roleData
            );
        }

        $this->command->info('✓ Created ' . count($roles) . ' roles');
    }
}
