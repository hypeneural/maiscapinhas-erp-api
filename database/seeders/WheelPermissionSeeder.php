<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeder para criar as permissões do módulo Wheel (Roleta nas TVs).
 * 
 * Uso: php artisan db:seed --class=WheelPermissionSeeder
 */
class WheelPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Geral
            [
                'name' => 'wheel.admin',
                'display_name' => 'Acesso ao Módulo Roleta',
                'description' => 'Permissão geral para acessar o módulo de roleta',
            ],

            // Screens (TVs)
            [
                'name' => 'wheel.screens.view',
                'display_name' => 'Ver TVs',
                'description' => 'Visualizar lista e detalhes das TVs',
            ],
            [
                'name' => 'wheel.screens.manage',
                'display_name' => 'Gerenciar TVs',
                'description' => 'Criar, editar, excluir TVs e gerar tokens',
            ],

            // Campaigns
            [
                'name' => 'wheel.campaigns.view',
                'display_name' => 'Ver Campanhas',
                'description' => 'Visualizar lista e detalhes das campanhas',
            ],
            [
                'name' => 'wheel.campaigns.manage',
                'display_name' => 'Gerenciar Campanhas',
                'description' => 'Criar, editar, ativar, pausar e encerrar campanhas',
            ],

            // Prizes
            [
                'name' => 'wheel.prizes.view',
                'display_name' => 'Ver Prêmios',
                'description' => 'Visualizar catálogo de prêmios',
            ],
            [
                'name' => 'wheel.prizes.manage',
                'display_name' => 'Gerenciar Prêmios',
                'description' => 'Criar, editar e desativar prêmios',
            ],

            // Inventory
            [
                'name' => 'wheel.inventory.manage',
                'display_name' => 'Gerenciar Estoque',
                'description' => 'Configurar limites e adicionar estoque de prêmios',
            ],

            // Analytics & Logs
            [
                'name' => 'wheel.analytics.view',
                'display_name' => 'Ver Analytics',
                'description' => 'Visualizar dashboard e estatísticas da roleta',
            ],
            [
                'name' => 'wheel.logs.view',
                'display_name' => 'Ver Logs',
                'description' => 'Visualizar logs de eventos e auditoria',
            ],
        ];

        $this->command->info('🎡 Criando permissões do módulo Wheel...');

        foreach ($permissions as $permissionData) {
            Permission::updateOrCreate(
                ['name' => $permissionData['name']],
                [
                    'display_name' => $permissionData['display_name'],
                    'description' => $permissionData['description'] ?? null,
                    'guard_name' => 'web',
                ]
            );
        }

        $this->command->info('✓ Criadas ' . count($permissions) . ' permissões');

        // Atribuir todas as permissões ao Super Admin
        $superAdmin = Role::where('name', Role::SUPER_ADMIN)->first();

        if ($superAdmin) {
            $permissionNames = array_column($permissions, 'name');
            $permissionModels = Permission::whereIn('name', $permissionNames)->get();

            $superAdmin->syncPermissions($permissionModels);

            $this->command->info('✓ Permissões atribuídas ao Super Admin');
        } else {
            $this->command->warn('⚠ Role super_admin não encontrada. Execute RoleSeeder primeiro.');
        }

        $this->command->newLine();
        $this->command->info('✅ Seeder do módulo Wheel concluído!');
    }
}
