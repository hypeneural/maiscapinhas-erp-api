<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // ========================================
        // SUPER ADMIN - Todas as permissões
        // ========================================
        $superAdmin = Role::where('name', Role::SUPER_ADMIN)->first();
        if ($superAdmin) {
            $allPermissions = Permission::pluck('id')->toArray();
            $superAdmin->permissions()->sync($allPermissions);
            $this->command->info("✓ Super Admin: {$superAdmin->permissions()->count()} permissions");
        }

        // ========================================
        // ADMIN - Quase todas, exceto super admin
        // ========================================
        $admin = Role::where('name', Role::ADMIN)->first();
        if ($admin) {
            $adminPerms = Permission::whereNotIn('name', [
                'admin.roles.manage',
                'admin.permissions.manage',
                'screen.admin.roles',
                'screen.admin.permissions',
                'admin.whatsapp.manage',
                'screen.admin.whatsapp',
            ])->pluck('id')->toArray();
            $admin->permissions()->sync($adminPerms);
            $this->command->info("✓ Admin: {$admin->permissions()->count()} permissions");
        }

        // ========================================
        // FÁBRICA
        // ========================================
        $fabrica = Role::where('name', Role::FABRICA)->first();
        if ($fabrica) {
            $fabricaPerms = Permission::whereIn('name', [
                'dashboard.view',
                'screen.dashboard',
                'fabrica.view',
                'fabrica.orders.accept',
                'fabrica.orders.dispatch',
                'screen.fabrica',
                'screen.fabrica.pedidos',
                'capas.view',
                'announcements.view',
                'screen.comunicados',
            ])->pluck('id')->toArray();
            $fabrica->permissions()->sync($fabricaPerms);
            $this->command->info("✓ Fábrica: {$fabrica->permissions()->count()} permissions");
        }

        // ========================================
        // GERENTE
        // ========================================
        $gerente = Role::where('name', Role::GERENTE)->first();
        if ($gerente) {
            $gerentePerms = Permission::whereIn('name', [
                // Dashboard
                'dashboard.view',
                'screen.dashboard',
                // Comunicados
                'announcements.view',
                'screen.comunicados',
                // Clientes
                'customers.view',
                'customers.create',
                'customers.update',
                'screen.clientes',
                // Payment methods
                'payment-methods.view',
                // Pedidos
                'pedidos.view',
                'pedidos.view-all',
                'pedidos.create',
                'pedidos.update',
                'pedidos.delete',
                'pedidos.status.update',
                'screen.pedidos',
                'screen.pedidos.list',
                'screen.pedidos.create',
                // Capas
                'capas.view',
                'capas.view-all',
                'capas.create',
                'capas.update',
                'capas.delete',
                'capas.status.update',
                'capas.payment.update',
                'capas.send-production',
                'screen.capas',
                'screen.capas.list',
                'screen.capas.create',
                'screen.capas.production',
                // Caixa
                'caixa.view',
                'caixa.shift.open',
                'caixa.closing.create',
                'caixa.closing.approve',
                'caixa.closing.reject',
                'screen.caixa',
                'screen.caixa.shift',
                'screen.caixa.closing',
                'screen.caixa.approve',
                // Relatórios
                'reports.view',
                'reports.sales',
                'reports.ranking',
                'reports.performance',
                'screen.reports',
                'screen.reports.sales',
                'screen.reports.ranking',
                // Faturamento
                'faturamento.extrato',
                'faturamento.bonus',
                'faturamento.comissoes',
                'screen.faturamento',
                'screen.faturamento.extrato',
                'screen.faturamento.bonus',
                'screen.faturamento.comissoes',
                // Gestão
                'screen.gestao',
                'screen.gestao.ranking',
                'screen.gestao.lojas',
                'screen.gestao.kpis',
                // Usuarios (da loja)
                'users.view',
                'screen.config.usuarios',
                // Features
                'feature.whatsapp-notifications',
                'feature.export-excel',
            ])->pluck('id')->toArray();
            $gerente->permissions()->sync($gerentePerms);
            $this->command->info("✓ Gerente: {$gerente->permissions()->count()} permissions");
        }

        // ========================================
        // CONFERENTE
        // ========================================
        $conferente = Role::where('name', Role::CONFERENTE)->first();
        if ($conferente) {
            $conferentePerms = Permission::whereIn('name', [
                // Dashboard
                'dashboard.view',
                'screen.dashboard',
                // Comunicados
                'announcements.view',
                'screen.comunicados',
                // Clientes
                'customers.view',
                'customers.create',
                'customers.update',
                'screen.clientes',
                // Payment methods
                'payment-methods.view',
                // Pedidos
                'pedidos.view',
                'pedidos.view-all',
                'pedidos.create',
                'pedidos.update',
                'pedidos.status.update',
                'screen.pedidos',
                'screen.pedidos.list',
                'screen.pedidos.create',
                // Capas
                'capas.view',
                'capas.view-all',
                'capas.create',
                'capas.update',
                'capas.status.update',
                'capas.payment.update',
                'screen.capas',
                'screen.capas.list',
                'screen.capas.create',
                // Caixa
                'caixa.view',
                'caixa.shift.open',
                'caixa.closing.create',
                'caixa.closing.approve',
                'caixa.closing.reject',
                'screen.caixa',
                'screen.caixa.shift',
                'screen.caixa.closing',
                'screen.caixa.approve',
                // Faturamento
                'faturamento.extrato',
                'faturamento.bonus',
                'faturamento.comissoes',
                'screen.faturamento',
                'screen.faturamento.extrato',
                'screen.faturamento.bonus',
                'screen.faturamento.comissoes',
            ])->pluck('id')->toArray();
            $conferente->permissions()->sync($conferentePerms);
            $this->command->info("✓ Conferente: {$conferente->permissions()->count()} permissions");
        }

        // ========================================
        // ESTOQUISTA
        // ========================================
        $estoquista = Role::where('name', Role::ESTOQUISTA)->first();
        if ($estoquista) {
            $estoquistaPerms = Permission::whereIn('name', [
                // Dashboard
                'dashboard.view',
                'screen.dashboard',
                // Comunicados
                'announcements.view',
                'screen.comunicados',
                // Payment methods
                'payment-methods.view',
                // Pedidos (somente visualização)
                'pedidos.view',
                'screen.pedidos',
                'screen.pedidos.list',
                // Capas (somente visualização)
                'capas.view',
                'screen.capas',
                'screen.capas.list',
            ])->pluck('id')->toArray();
            $estoquista->permissions()->sync($estoquistaPerms);
            $this->command->info("✓ Estoquista: {$estoquista->permissions()->count()} permissions");
        }

        // ========================================
        // VENDEDOR
        // ========================================
        $vendedor = Role::where('name', Role::VENDEDOR)->first();
        if ($vendedor) {
            $vendedorPerms = Permission::whereIn('name', [
                // Dashboard
                'dashboard.view',
                'screen.dashboard',
                // Comunicados
                'announcements.view',
                'screen.comunicados',
                // Clientes
                'customers.view',
                'customers.create',
                'customers.update',
                'screen.clientes',
                // Payment methods
                'payment-methods.view',
                // Pedidos (próprios)
                'pedidos.view',
                'pedidos.create',
                'pedidos.update',
                'pedidos.status.update',
                'screen.pedidos',
                'screen.pedidos.list',
                'screen.pedidos.create',
                // Capas (próprias)
                'capas.view',
                'capas.create',
                'capas.update',
                'capas.status.update',
                'capas.payment.update',
                'screen.capas',
                'screen.capas.list',
                'screen.capas.create',
                // Caixa (próprio turno)
                'caixa.shift.open',
                'caixa.closing.create',
                'screen.caixa',
                'screen.caixa.shift',
                'screen.caixa.closing',
                // Faturamento (próprio)
                'faturamento.extrato',
                'faturamento.bonus',
                'faturamento.comissoes',
                'screen.faturamento',
                'screen.faturamento.extrato',
                'screen.faturamento.bonus',
                'screen.faturamento.comissoes',
            ])->pluck('id')->toArray();
            $vendedor->permissions()->sync($vendedorPerms);
            $this->command->info("✓ Vendedor: {$vendedor->permissions()->count()} permissions");
        }
    }
}
