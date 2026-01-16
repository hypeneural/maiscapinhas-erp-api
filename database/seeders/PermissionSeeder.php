<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // ============================================
            // DASHBOARD
            // ============================================
            ['name' => 'dashboard.view', 'display_name' => 'Ver dashboard', 'type' => 'ability', 'module' => 'dashboard'],
            ['name' => 'screen.dashboard', 'display_name' => 'Tela Dashboard', 'type' => 'screen', 'module' => 'dashboard'],

            // ============================================
            // CLIENTES (customers)
            // ============================================
            ['name' => 'customers.view', 'display_name' => 'Ver clientes (próprios)', 'type' => 'ability', 'module' => 'customers'],
            ['name' => 'customers.view-all', 'display_name' => 'Ver todos os clientes da loja', 'type' => 'ability', 'module' => 'customers'],
            ['name' => 'customers.view-global', 'display_name' => 'Ver clientes de todas as lojas', 'type' => 'ability', 'module' => 'customers'],
            ['name' => 'customers.create', 'display_name' => 'Criar cliente', 'type' => 'ability', 'module' => 'customers'],
            ['name' => 'customers.update', 'display_name' => 'Editar cliente', 'type' => 'ability', 'module' => 'customers'],
            ['name' => 'customers.delete', 'display_name' => 'Excluir cliente', 'type' => 'ability', 'module' => 'customers'],
            ['name' => 'customers.merge', 'display_name' => 'Mesclar clientes duplicados', 'type' => 'ability', 'module' => 'customers'],
            ['name' => 'screen.clientes', 'display_name' => 'Menu Clientes', 'type' => 'screen', 'module' => 'customers'],
            ['name' => 'screen.clientes.list', 'display_name' => 'Tela Lista de Clientes', 'type' => 'screen', 'module' => 'customers'],
            ['name' => 'screen.clientes.create', 'display_name' => 'Tela Novo Cliente', 'type' => 'screen', 'module' => 'customers'],
            ['name' => 'screen.clientes.detail', 'display_name' => 'Tela Detalhe do Cliente', 'type' => 'screen', 'module' => 'customers'],
            ['name' => 'screen.clientes.edit', 'display_name' => 'Tela Editar Cliente', 'type' => 'screen', 'module' => 'customers'],

            // ============================================
            // FORMAS DE PAGAMENTO
            // ============================================
            ['name' => 'payment-methods.view', 'display_name' => 'Ver formas de pagamento', 'type' => 'ability', 'module' => 'payment-methods'],
            ['name' => 'payment-methods.create', 'display_name' => 'Criar forma de pagamento', 'type' => 'ability', 'module' => 'payment-methods'],
            ['name' => 'payment-methods.update', 'display_name' => 'Editar forma de pagamento', 'type' => 'ability', 'module' => 'payment-methods'],
            ['name' => 'payment-methods.delete', 'display_name' => 'Excluir forma de pagamento', 'type' => 'ability', 'module' => 'payment-methods'],
            ['name' => 'payment-methods.toggle-status', 'display_name' => 'Ativar/desativar forma de pagamento', 'type' => 'ability', 'module' => 'payment-methods'],
            ['name' => 'screen.config.payment-methods', 'display_name' => 'Tela Formas de Pagamento', 'type' => 'screen', 'module' => 'payment-methods'],

            // ============================================
            // PEDIDOS
            // ============================================
            ['name' => 'pedidos.view', 'display_name' => 'Ver pedidos (próprios)', 'type' => 'ability', 'module' => 'pedidos'],
            ['name' => 'pedidos.view-all', 'display_name' => 'Ver todos os pedidos da loja', 'type' => 'ability', 'module' => 'pedidos'],
            ['name' => 'pedidos.view-global', 'display_name' => 'Ver pedidos de todas as lojas', 'type' => 'ability', 'module' => 'pedidos'],
            ['name' => 'pedidos.create', 'display_name' => 'Criar pedido', 'type' => 'ability', 'module' => 'pedidos'],
            ['name' => 'pedidos.update', 'display_name' => 'Editar pedido', 'type' => 'ability', 'module' => 'pedidos'],
            ['name' => 'pedidos.delete', 'display_name' => 'Excluir pedido', 'type' => 'ability', 'module' => 'pedidos'],
            ['name' => 'pedidos.cancel', 'display_name' => 'Cancelar pedido', 'type' => 'ability', 'module' => 'pedidos'],
            ['name' => 'pedidos.status.update', 'display_name' => 'Alterar status do pedido', 'type' => 'ability', 'module' => 'pedidos'],
            ['name' => 'pedidos.status.revert', 'display_name' => 'Reverter status do pedido', 'type' => 'ability', 'module' => 'pedidos'],
            ['name' => 'pedidos.bulk-status', 'display_name' => 'Alterar status em lote', 'type' => 'ability', 'module' => 'pedidos'],
            ['name' => 'pedidos.print', 'display_name' => 'Imprimir comprovante', 'type' => 'ability', 'module' => 'pedidos'],
            ['name' => 'pedidos.send-whatsapp', 'display_name' => 'Enviar notificação WhatsApp', 'type' => 'ability', 'module' => 'pedidos'],
            ['name' => 'pedidos.duplicate', 'display_name' => 'Duplicar pedido', 'type' => 'ability', 'module' => 'pedidos'],
            ['name' => 'screen.pedidos', 'display_name' => 'Menu Pedidos', 'type' => 'screen', 'module' => 'pedidos'],
            ['name' => 'screen.pedidos.list', 'display_name' => 'Tela Lista de Pedidos', 'type' => 'screen', 'module' => 'pedidos'],
            ['name' => 'screen.pedidos.create', 'display_name' => 'Tela Novo Pedido', 'type' => 'screen', 'module' => 'pedidos'],
            ['name' => 'screen.pedidos.detail', 'display_name' => 'Tela Detalhe do Pedido', 'type' => 'screen', 'module' => 'pedidos'],
            ['name' => 'screen.pedidos.edit', 'display_name' => 'Tela Editar Pedido', 'type' => 'screen', 'module' => 'pedidos'],
            ['name' => 'screen.pedidos.bulk', 'display_name' => 'Tela Operações em Lote', 'type' => 'screen', 'module' => 'pedidos'],

            // ============================================
            // CAPAS PERSONALIZADAS
            // ============================================
            ['name' => 'capas.view', 'display_name' => 'Ver capas (próprias)', 'type' => 'ability', 'module' => 'capas'],
            ['name' => 'capas.view-all', 'display_name' => 'Ver todas as capas da loja', 'type' => 'ability', 'module' => 'capas'],
            ['name' => 'capas.view-global', 'display_name' => 'Ver capas de todas as lojas', 'type' => 'ability', 'module' => 'capas'],
            ['name' => 'capas.create', 'display_name' => 'Criar capa', 'type' => 'ability', 'module' => 'capas'],
            ['name' => 'capas.update', 'display_name' => 'Editar capa', 'type' => 'ability', 'module' => 'capas'],
            ['name' => 'capas.delete', 'display_name' => 'Excluir capa', 'type' => 'ability', 'module' => 'capas'],
            ['name' => 'capas.cancel', 'display_name' => 'Cancelar capa', 'type' => 'ability', 'module' => 'capas'],
            ['name' => 'capas.approve', 'display_name' => 'Aprovar capa', 'type' => 'ability', 'module' => 'capas'],
            ['name' => 'capas.reject', 'display_name' => 'Rejeitar capa', 'type' => 'ability', 'module' => 'capas'],
            ['name' => 'capas.status.update', 'display_name' => 'Alterar status da capa', 'type' => 'ability', 'module' => 'capas'],
            ['name' => 'capas.payment.update', 'display_name' => 'Registrar pagamento', 'type' => 'ability', 'module' => 'capas'],
            ['name' => 'capas.send-production', 'display_name' => 'Enviar para produção', 'type' => 'ability', 'module' => 'capas'],
            ['name' => 'capas.bulk-status', 'display_name' => 'Alterar status em lote', 'type' => 'ability', 'module' => 'capas'],
            ['name' => 'capas.print', 'display_name' => 'Imprimir capa', 'type' => 'ability', 'module' => 'capas'],
            ['name' => 'capas.download-image', 'display_name' => 'Baixar imagem da capa', 'type' => 'ability', 'module' => 'capas'],
            ['name' => 'capas.send-whatsapp', 'display_name' => 'Enviar notificação WhatsApp', 'type' => 'ability', 'module' => 'capas'],
            ['name' => 'screen.capas', 'display_name' => 'Menu Capas', 'type' => 'screen', 'module' => 'capas'],
            ['name' => 'screen.capas.list', 'display_name' => 'Tela Lista de Capas', 'type' => 'screen', 'module' => 'capas'],
            ['name' => 'screen.capas.create', 'display_name' => 'Tela Nova Capa', 'type' => 'screen', 'module' => 'capas'],
            ['name' => 'screen.capas.detail', 'display_name' => 'Tela Detalhe da Capa', 'type' => 'screen', 'module' => 'capas'],
            ['name' => 'screen.capas.edit', 'display_name' => 'Tela Editar Capa', 'type' => 'screen', 'module' => 'capas'],
            ['name' => 'screen.capas.production', 'display_name' => 'Tela Enviar Produção', 'type' => 'screen', 'module' => 'capas'],

            // ============================================
            // CAIXA
            // ============================================
            ['name' => 'caixa.view', 'display_name' => 'Ver fechamentos', 'type' => 'ability', 'module' => 'caixa'],
            ['name' => 'caixa.view-all', 'display_name' => 'Ver fechamentos de todos', 'type' => 'ability', 'module' => 'caixa'],
            ['name' => 'caixa.shift.open', 'display_name' => 'Abrir turno', 'type' => 'ability', 'module' => 'caixa'],
            ['name' => 'caixa.closing.create', 'display_name' => 'Fazer fechamento', 'type' => 'ability', 'module' => 'caixa'],
            ['name' => 'caixa.closing.approve', 'display_name' => 'Aprovar fechamento', 'type' => 'ability', 'module' => 'caixa'],
            ['name' => 'caixa.closing.reject', 'display_name' => 'Rejeitar fechamento', 'type' => 'ability', 'module' => 'caixa'],
            ['name' => 'caixa.reopen', 'display_name' => 'Reabrir fechamento rejeitado', 'type' => 'ability', 'module' => 'caixa'],
            ['name' => 'caixa.export', 'display_name' => 'Exportar relatório de caixa', 'type' => 'ability', 'module' => 'caixa'],
            ['name' => 'screen.caixa', 'display_name' => 'Menu Caixa', 'type' => 'screen', 'module' => 'caixa'],
            ['name' => 'screen.caixa.shift', 'display_name' => 'Tela Meu Turno', 'type' => 'screen', 'module' => 'caixa'],
            ['name' => 'screen.caixa.closing', 'display_name' => 'Tela Fechamento', 'type' => 'screen', 'module' => 'caixa'],
            ['name' => 'screen.caixa.approve', 'display_name' => 'Tela Aprovar Fechamentos', 'type' => 'screen', 'module' => 'caixa'],
            ['name' => 'screen.caixa.history', 'display_name' => 'Tela Histórico de Fechamentos', 'type' => 'screen', 'module' => 'caixa'],

            // ============================================
            // VENDAS (SALES)
            // ============================================
            ['name' => 'sales.view', 'display_name' => 'Ver vendas (próprias)', 'type' => 'ability', 'module' => 'sales'],
            ['name' => 'sales.view-all', 'display_name' => 'Ver todas as vendas da loja', 'type' => 'ability', 'module' => 'sales'],
            ['name' => 'sales.view-global', 'display_name' => 'Ver vendas de todas as lojas', 'type' => 'ability', 'module' => 'sales'],
            ['name' => 'sales.create', 'display_name' => 'Registrar venda', 'type' => 'ability', 'module' => 'sales'],
            ['name' => 'sales.update', 'display_name' => 'Editar venda', 'type' => 'ability', 'module' => 'sales'],
            ['name' => 'sales.delete', 'display_name' => 'Excluir venda', 'type' => 'ability', 'module' => 'sales'],
            ['name' => 'screen.sales', 'display_name' => 'Tela Vendas', 'type' => 'screen', 'module' => 'sales'],

            // ============================================
            // RELATÓRIOS
            // ============================================
            ['name' => 'reports.view', 'display_name' => 'Ver relatórios', 'type' => 'ability', 'module' => 'reports'],
            ['name' => 'reports.sales', 'display_name' => 'Relatório de vendas', 'type' => 'ability', 'module' => 'reports'],
            ['name' => 'reports.ranking', 'display_name' => 'Relatório de ranking', 'type' => 'ability', 'module' => 'reports'],
            ['name' => 'reports.performance', 'display_name' => 'Relatório de performance', 'type' => 'ability', 'module' => 'reports'],
            ['name' => 'reports.export', 'display_name' => 'Exportar relatórios', 'type' => 'ability', 'module' => 'reports'],
            ['name' => 'screen.reports', 'display_name' => 'Menu Relatórios', 'type' => 'screen', 'module' => 'reports'],
            ['name' => 'screen.reports.sales', 'display_name' => 'Tela Vendas', 'type' => 'screen', 'module' => 'reports'],
            ['name' => 'screen.reports.ranking', 'display_name' => 'Tela Ranking', 'type' => 'screen', 'module' => 'reports'],

            // ============================================
            // FATURAMENTO
            // ============================================
            ['name' => 'faturamento.extrato', 'display_name' => 'Ver extrato de vendas', 'type' => 'ability', 'module' => 'faturamento'],
            ['name' => 'faturamento.bonus', 'display_name' => 'Ver bônus', 'type' => 'ability', 'module' => 'faturamento'],
            ['name' => 'faturamento.comissoes', 'display_name' => 'Ver comissões', 'type' => 'ability', 'module' => 'faturamento'],
            ['name' => 'screen.faturamento', 'display_name' => 'Menu Faturamento', 'type' => 'screen', 'module' => 'faturamento'],
            ['name' => 'screen.faturamento.extrato', 'display_name' => 'Tela Extrato', 'type' => 'screen', 'module' => 'faturamento'],
            ['name' => 'screen.faturamento.bonus', 'display_name' => 'Tela Bônus', 'type' => 'screen', 'module' => 'faturamento'],
            ['name' => 'screen.faturamento.comissoes', 'display_name' => 'Tela Comissões', 'type' => 'screen', 'module' => 'faturamento'],

            // ============================================
            // PRODUÇÃO
            // ============================================
            ['name' => 'producao.view', 'display_name' => 'Ver produção', 'type' => 'ability', 'module' => 'producao'],
            ['name' => 'producao.cart.add', 'display_name' => 'Adicionar ao carrinho', 'type' => 'ability', 'module' => 'producao'],
            ['name' => 'producao.cart.remove', 'display_name' => 'Remover do carrinho', 'type' => 'ability', 'module' => 'producao'],
            ['name' => 'producao.cart.close', 'display_name' => 'Fechar carrinho', 'type' => 'ability', 'module' => 'producao'],
            ['name' => 'producao.orders.receive', 'display_name' => 'Receber pedido de produção', 'type' => 'ability', 'module' => 'producao'],
            ['name' => 'producao.orders.cancel', 'display_name' => 'Cancelar pedido de produção', 'type' => 'ability', 'module' => 'producao'],
            ['name' => 'screen.producao', 'display_name' => 'Menu Produção', 'type' => 'screen', 'module' => 'producao'],
            ['name' => 'screen.producao.cart', 'display_name' => 'Tela Carrinho', 'type' => 'screen', 'module' => 'producao'],
            ['name' => 'screen.producao.orders', 'display_name' => 'Tela Pedidos Produção', 'type' => 'screen', 'module' => 'producao'],

            // ============================================
            // FÁBRICA
            // ============================================
            ['name' => 'fabrica.view', 'display_name' => 'Acessar portal da fábrica', 'type' => 'ability', 'module' => 'fabrica'],
            ['name' => 'fabrica.orders.accept', 'display_name' => 'Aceitar pedido', 'type' => 'ability', 'module' => 'fabrica'],
            ['name' => 'fabrica.orders.dispatch', 'display_name' => 'Despachar pedido', 'type' => 'ability', 'module' => 'fabrica'],
            ['name' => 'screen.fabrica', 'display_name' => 'Menu Fábrica', 'type' => 'screen', 'module' => 'fabrica'],
            ['name' => 'screen.fabrica.dashboard', 'display_name' => 'Dashboard da Fábrica', 'type' => 'screen', 'module' => 'fabrica'],
            ['name' => 'screen.fabrica.pedidos', 'display_name' => 'Tela Pedidos Fábrica', 'type' => 'screen', 'module' => 'fabrica'],
            ['name' => 'screen.fabrica.dispatch', 'display_name' => 'Tela Despacho', 'type' => 'screen', 'module' => 'fabrica'],

            // ============================================
            // USUARIOS
            // ============================================
            ['name' => 'users.view', 'display_name' => 'Ver usuários da loja', 'type' => 'ability', 'module' => 'users'],
            ['name' => 'users.view-all', 'display_name' => 'Ver todos os usuários', 'type' => 'ability', 'module' => 'users'],
            ['name' => 'users.create', 'display_name' => 'Criar usuário', 'type' => 'ability', 'module' => 'users'],
            ['name' => 'users.update', 'display_name' => 'Editar usuário', 'type' => 'ability', 'module' => 'users'],
            ['name' => 'users.delete', 'display_name' => 'Excluir usuário', 'type' => 'ability', 'module' => 'users'],
            ['name' => 'screen.config.usuarios', 'display_name' => 'Tela Usuários', 'type' => 'screen', 'module' => 'users'],

            // ============================================
            // LOJAS
            // ============================================
            ['name' => 'stores.view', 'display_name' => 'Ver lojas', 'type' => 'ability', 'module' => 'stores'],
            ['name' => 'stores.create', 'display_name' => 'Criar loja', 'type' => 'ability', 'module' => 'stores'],
            ['name' => 'stores.update', 'display_name' => 'Editar loja', 'type' => 'ability', 'module' => 'stores'],
            ['name' => 'stores.delete', 'display_name' => 'Excluir loja', 'type' => 'ability', 'module' => 'stores'],
            ['name' => 'screen.admin.stores', 'display_name' => 'Tela Lojas', 'type' => 'screen', 'module' => 'stores'],

            // ============================================
            // COMUNICADOS
            // ============================================
            ['name' => 'announcements.view', 'display_name' => 'Ver comunicados', 'type' => 'ability', 'module' => 'announcements'],
            ['name' => 'announcements.create', 'display_name' => 'Criar comunicado', 'type' => 'ability', 'module' => 'announcements'],
            ['name' => 'announcements.update', 'display_name' => 'Editar comunicado', 'type' => 'ability', 'module' => 'announcements'],
            ['name' => 'announcements.delete', 'display_name' => 'Excluir comunicado', 'type' => 'ability', 'module' => 'announcements'],
            ['name' => 'screen.comunicados', 'display_name' => 'Tela Comunicados', 'type' => 'screen', 'module' => 'announcements'],
            ['name' => 'screen.config.comunicados', 'display_name' => 'Tela Gerenciar Comunicados', 'type' => 'screen', 'module' => 'announcements'],

            // ============================================
            // ADMINISTRAÇÃO
            // ============================================
            ['name' => 'admin.audit.view', 'display_name' => 'Ver logs de auditoria', 'type' => 'ability', 'module' => 'admin'],
            ['name' => 'admin.audit.export', 'display_name' => 'Exportar logs de auditoria', 'type' => 'ability', 'module' => 'admin'],
            ['name' => 'admin.catalog.manage', 'display_name' => 'Gerenciar catálogo de telefones', 'type' => 'ability', 'module' => 'admin'],
            ['name' => 'admin.whatsapp.manage', 'display_name' => 'Gerenciar instâncias WhatsApp', 'type' => 'ability', 'module' => 'admin'],
            ['name' => 'admin.roles.manage', 'display_name' => 'Gerenciar roles', 'type' => 'ability', 'module' => 'admin'],
            ['name' => 'admin.permissions.manage', 'display_name' => 'Gerenciar permissões', 'type' => 'ability', 'module' => 'admin'],
            ['name' => 'admin.users.impersonate', 'display_name' => 'Logar como outro usuário', 'type' => 'ability', 'module' => 'admin'],
            ['name' => 'admin.system.maintenance', 'display_name' => 'Ativar modo manutenção', 'type' => 'ability', 'module' => 'admin'],
            ['name' => 'screen.admin', 'display_name' => 'Menu Administração', 'type' => 'screen', 'module' => 'admin'],
            ['name' => 'screen.admin.logs', 'display_name' => 'Tela Logs', 'type' => 'screen', 'module' => 'admin'],
            ['name' => 'screen.admin.catalogo', 'display_name' => 'Tela Catálogo Telefones', 'type' => 'screen', 'module' => 'admin'],
            ['name' => 'screen.admin.whatsapp', 'display_name' => 'Tela WhatsApp', 'type' => 'screen', 'module' => 'admin'],
            ['name' => 'screen.admin.roles', 'display_name' => 'Tela Roles', 'type' => 'screen', 'module' => 'admin'],
            ['name' => 'screen.admin.permissions', 'display_name' => 'Tela Permissões', 'type' => 'screen', 'module' => 'admin'],

            // ============================================
            // SUPER ADMIN
            // ============================================
            ['name' => 'screen.super-admin', 'display_name' => 'Menu Super Admin', 'type' => 'screen', 'module' => 'super-admin'],
            ['name' => 'screen.super-admin.whatsapp-instances', 'display_name' => 'Tela Instâncias WhatsApp', 'type' => 'screen', 'module' => 'super-admin'],
            ['name' => 'screen.super-admin.permissions', 'display_name' => 'Tela Gestão de Permissões', 'type' => 'screen', 'module' => 'super-admin'],
            ['name' => 'screen.super-admin.users', 'display_name' => 'Tela Gestão de Usuários Global', 'type' => 'screen', 'module' => 'super-admin'],

            // ============================================
            // CONFIGURAÇÕES
            // ============================================
            ['name' => 'config.metas', 'display_name' => 'Configurar metas mensais', 'type' => 'ability', 'module' => 'config'],
            ['name' => 'config.bonus', 'display_name' => 'Configurar tabela de bônus', 'type' => 'ability', 'module' => 'config'],
            ['name' => 'config.comissoes', 'display_name' => 'Configurar regras de comissão', 'type' => 'ability', 'module' => 'config'],
            ['name' => 'screen.config', 'display_name' => 'Menu Configurações', 'type' => 'screen', 'module' => 'config'],
            ['name' => 'screen.config.metas', 'display_name' => 'Tela Metas', 'type' => 'screen', 'module' => 'config'],
            ['name' => 'screen.config.bonus', 'display_name' => 'Tela Bônus', 'type' => 'screen', 'module' => 'config'],
            ['name' => 'screen.config.comissoes', 'display_name' => 'Tela Comissões', 'type' => 'screen', 'module' => 'config'],
            ['name' => 'screen.config.brands', 'display_name' => 'Tela Marcas de Aparelhos', 'type' => 'screen', 'module' => 'config'],
            ['name' => 'screen.config.models', 'display_name' => 'Tela Modelos de Aparelhos', 'type' => 'screen', 'module' => 'config'],

            // ============================================
            // GESTÃO
            // ============================================
            ['name' => 'screen.gestao', 'display_name' => 'Menu Gestão', 'type' => 'screen', 'module' => 'gestao'],
            ['name' => 'screen.gestao.ranking', 'display_name' => 'Tela Ranking', 'type' => 'screen', 'module' => 'gestao'],
            ['name' => 'screen.gestao.lojas', 'display_name' => 'Tela Performance Lojas', 'type' => 'screen', 'module' => 'gestao'],
            ['name' => 'screen.gestao.kpis', 'display_name' => 'Tela KPIs', 'type' => 'screen', 'module' => 'gestao'],

            // ============================================
            // METAS (GOALS)
            // ============================================
            ['name' => 'goals.view', 'display_name' => 'Ver metas', 'type' => 'ability', 'module' => 'goals'],
            ['name' => 'goals.create', 'display_name' => 'Criar meta', 'type' => 'ability', 'module' => 'goals'],
            ['name' => 'goals.update', 'display_name' => 'Editar meta', 'type' => 'ability', 'module' => 'goals'],
            ['name' => 'goals.delete', 'display_name' => 'Excluir meta', 'type' => 'ability', 'module' => 'goals'],
            ['name' => 'goals.splits', 'display_name' => 'Definir splits de meta', 'type' => 'ability', 'module' => 'goals'],

            // ============================================
            // REGRAS (RULES)
            // ============================================
            ['name' => 'rules.bonus.view', 'display_name' => 'Ver regras de bônus', 'type' => 'ability', 'module' => 'rules'],
            ['name' => 'rules.bonus.manage', 'display_name' => 'Gerenciar regras de bônus', 'type' => 'ability', 'module' => 'rules'],
            ['name' => 'rules.commission.view', 'display_name' => 'Ver regras de comissão', 'type' => 'ability', 'module' => 'rules'],
            ['name' => 'rules.commission.manage', 'display_name' => 'Gerenciar regras de comissão', 'type' => 'ability', 'module' => 'rules'],

            // ============================================
            // ANALYTICS
            // ============================================
            ['name' => 'analytics.view', 'display_name' => 'Ver analytics', 'type' => 'ability', 'module' => 'analytics'],
            ['name' => 'analytics.shift', 'display_name' => 'Ver analytics de turno', 'type' => 'ability', 'module' => 'analytics'],

            // ============================================
            // FEATURES
            // ============================================
            ['name' => 'feature.whatsapp-notifications', 'display_name' => 'Enviar notificações WhatsApp', 'type' => 'feature', 'module' => 'features'],
            ['name' => 'feature.bulk-operations', 'display_name' => 'Operações em lote', 'type' => 'feature', 'module' => 'features'],
            ['name' => 'feature.export-excel', 'display_name' => 'Exportar para Excel', 'type' => 'feature', 'module' => 'features'],
            ['name' => 'feature.dark-mode', 'display_name' => 'Tema escuro', 'type' => 'feature', 'module' => 'features'],
            ['name' => 'feature.beta-features', 'display_name' => 'Acesso a features beta', 'type' => 'feature', 'module' => 'features'],
            ['name' => 'feature.advanced-search', 'display_name' => 'Busca avançada', 'type' => 'feature', 'module' => 'features'],
            ['name' => 'feature.keyboard-shortcuts', 'display_name' => 'Atalhos de teclado', 'type' => 'feature', 'module' => 'features'],
            ['name' => 'feature.offline-mode', 'display_name' => 'Modo offline (PWA)', 'type' => 'feature', 'module' => 'features'],
            ['name' => 'feature.notifications-push', 'display_name' => 'Notificações push', 'type' => 'feature', 'module' => 'features'],
        ];

        foreach ($permissions as $index => $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                [
                    'display_name' => $permission['display_name'],
                    'type' => $permission['type'],
                    'module' => $permission['module'],
                    'description' => $permission['description'] ?? null,
                    'sort_order' => $index,
                    'guard_name' => 'web',
                ]
            );
        }

        $this->command->info('✓ Created ' . count($permissions) . ' permissions');
    }
}
