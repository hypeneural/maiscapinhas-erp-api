<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Builds the menu structure based on user permissions.
 */
class MenuBuilder
{
    public function __construct(
        private PermissionResolver $permissionResolver
    ) {
    }

    /**
     * Build the menu for a user.
     *
     * @return array<int, array{id: string, label: string, icon: string, path: string, screen: string, children?: array}>
     */
    public function buildMenu(User $user): array
    {
        $screens = $this->permissionResolver->resolveScreens($user);
        $allScreens = array_merge(
            $screens['global'],
            ...array_values($screens['by_store'])
        );

        $menu = $this->getMenuStructure();

        return $this->filterMenu($menu, $allScreens, $user->is_super_admin);
    }

    /**
     * Filter menu items based on available screens.
     */
    private function filterMenu(array $menu, array $availableScreens, bool $isSuperAdmin): array
    {
        if ($isSuperAdmin) {
            return $menu;
        }

        return collect($menu)
            ->filter(function ($item) use ($availableScreens) {
                return in_array($item['screen'], $availableScreens);
            })
            ->map(function ($item) use ($availableScreens) {
                if (isset($item['children'])) {
                    $item['children'] = $this->filterMenu($item['children'], $availableScreens, false);
                    if (empty($item['children'])) {
                        unset($item['children']);
                    }
                }
                return $item;
            })
            ->values()
            ->toArray();
    }

    /**
     * Get the full menu structure.
     */
    private function getMenuStructure(): array
    {
        return [
            [
                'id' => 'dashboard',
                'label' => 'Dashboard',
                'icon' => 'LayoutDashboard',
                'path' => '/',
                'screen' => 'screen.dashboard',
            ],
            [
                'id' => 'comunicados',
                'label' => 'Comunicados',
                'icon' => 'Bell',
                'path' => '/comunicados',
                'screen' => 'screen.comunicados',
            ],
            [
                'id' => 'clientes',
                'label' => 'Clientes',
                'icon' => 'Users',
                'path' => '/clientes',
                'screen' => 'screen.clientes',
            ],
            [
                'id' => 'pedidos',
                'label' => 'Pedidos',
                'icon' => 'FileCheck',
                'path' => '/pedidos',
                'screen' => 'screen.pedidos',
                'children' => [
                    [
                        'id' => 'pedidos-list',
                        'label' => 'Lista',
                        'icon' => 'List',
                        'path' => '/pedidos',
                        'screen' => 'screen.pedidos.list',
                    ],
                    [
                        'id' => 'pedidos-new',
                        'label' => 'Novo Pedido',
                        'icon' => 'Plus',
                        'path' => '/pedidos/new',
                        'screen' => 'screen.pedidos.create',
                    ],
                ],
            ],
            [
                'id' => 'capas',
                'label' => 'Capas Personalizadas',
                'icon' => 'Palette',
                'path' => '/capas',
                'screen' => 'screen.capas',
                'children' => [
                    [
                        'id' => 'capas-list',
                        'label' => 'Lista',
                        'icon' => 'List',
                        'path' => '/capas',
                        'screen' => 'screen.capas.list',
                    ],
                    [
                        'id' => 'capas-new',
                        'label' => 'Nova Capa',
                        'icon' => 'Plus',
                        'path' => '/capas/new',
                        'screen' => 'screen.capas.create',
                    ],
                    [
                        'id' => 'capas-production',
                        'label' => 'Enviar Produção',
                        'icon' => 'Send',
                        'path' => '/capas/producao',
                        'screen' => 'screen.capas.production',
                    ],
                ],
            ],
            [
                'id' => 'caixa',
                'label' => 'Caixa',
                'icon' => 'Wallet',
                'path' => '/caixa',
                'screen' => 'screen.caixa',
                'children' => [
                    [
                        'id' => 'caixa-shift',
                        'label' => 'Meu Turno',
                        'icon' => 'Clock',
                        'path' => '/caixa',
                        'screen' => 'screen.caixa.shift',
                    ],
                    [
                        'id' => 'caixa-closing',
                        'label' => 'Fechamento',
                        'icon' => 'Calculator',
                        'path' => '/caixa/fechamento',
                        'screen' => 'screen.caixa.closing',
                    ],
                    [
                        'id' => 'caixa-approve',
                        'label' => 'Aprovar',
                        'icon' => 'CheckCircle',
                        'path' => '/caixa/aprovar',
                        'screen' => 'screen.caixa.approve',
                    ],
                ],
            ],
            [
                'id' => 'faturamento',
                'label' => 'Faturamento',
                'icon' => 'DollarSign',
                'path' => '/faturamento',
                'screen' => 'screen.faturamento',
                'children' => [
                    [
                        'id' => 'extrato',
                        'label' => 'Extrato de Vendas',
                        'icon' => 'FileText',
                        'path' => '/faturamento/extrato',
                        'screen' => 'screen.faturamento.extrato',
                    ],
                    [
                        'id' => 'bonus',
                        'label' => 'Meus Bônus',
                        'icon' => 'Gift',
                        'path' => '/faturamento/bonus',
                        'screen' => 'screen.faturamento.bonus',
                    ],
                    [
                        'id' => 'comissoes',
                        'label' => 'Minhas Comissões',
                        'icon' => 'TrendingUp',
                        'path' => '/faturamento/comissoes',
                        'screen' => 'screen.faturamento.comissoes',
                    ],
                ],
            ],
            [
                'id' => 'gestao',
                'label' => 'Gestão',
                'icon' => 'BarChart3',
                'path' => '/gestao',
                'screen' => 'screen.gestao',
                'children' => [
                    [
                        'id' => 'ranking',
                        'label' => 'Ranking de Vendas',
                        'icon' => 'Trophy',
                        'path' => '/gestao/ranking',
                        'screen' => 'screen.gestao.ranking',
                    ],
                    [
                        'id' => 'lojas',
                        'label' => 'Performance Lojas',
                        'icon' => 'Store',
                        'path' => '/gestao/lojas',
                        'screen' => 'screen.gestao.lojas',
                    ],
                    [
                        'id' => 'kpis',
                        'label' => 'KPIs',
                        'icon' => 'Target',
                        'path' => '/gestao/kpis',
                        'screen' => 'screen.gestao.kpis',
                    ],
                ],
            ],
            [
                'id' => 'producao',
                'label' => 'Produção',
                'icon' => 'Factory',
                'path' => '/producao',
                'screen' => 'screen.producao',
                'children' => [
                    [
                        'id' => 'carrinho',
                        'label' => 'Carrinho',
                        'icon' => 'ShoppingCart',
                        'path' => '/producao/carrinho',
                        'screen' => 'screen.producao.carrinho',
                    ],
                    [
                        'id' => 'producao-pedidos',
                        'label' => 'Pedidos',
                        'icon' => 'Package',
                        'path' => '/producao/pedidos',
                        'screen' => 'screen.producao.pedidos',
                    ],
                ],
            ],
            [
                'id' => 'fabrica',
                'label' => 'Fábrica',
                'icon' => 'Building2',
                'path' => '/fabrica',
                'screen' => 'screen.fabrica',
                'children' => [
                    [
                        'id' => 'fabrica-pedidos',
                        'label' => 'Pedidos',
                        'icon' => 'ClipboardList',
                        'path' => '/fabrica/pedidos',
                        'screen' => 'screen.fabrica.pedidos',
                    ],
                ],
            ],
            [
                'id' => 'config',
                'label' => 'Configurações',
                'icon' => 'Settings',
                'path' => '/config',
                'screen' => 'screen.config',
                'children' => [
                    [
                        'id' => 'config-usuarios',
                        'label' => 'Usuários',
                        'icon' => 'Users',
                        'path' => '/config/usuarios',
                        'screen' => 'screen.config.usuarios',
                    ],
                    [
                        'id' => 'config-metas',
                        'label' => 'Metas Mensais',
                        'icon' => 'Target',
                        'path' => '/config/metas',
                        'screen' => 'screen.config.metas',
                    ],
                    [
                        'id' => 'config-bonus',
                        'label' => 'Tabela de Bônus',
                        'icon' => 'Gift',
                        'path' => '/config/bonus',
                        'screen' => 'screen.config.bonus',
                    ],
                    [
                        'id' => 'config-comissoes',
                        'label' => 'Regras de Comissão',
                        'icon' => 'Percent',
                        'path' => '/config/comissoes',
                        'screen' => 'screen.config.comissoes',
                    ],
                    [
                        'id' => 'config-payment-methods',
                        'label' => 'Formas de Pagamento',
                        'icon' => 'CreditCard',
                        'path' => '/config/formas-pagamento',
                        'screen' => 'screen.config.payment-methods',
                    ],
                    [
                        'id' => 'config-comunicados',
                        'label' => 'Comunicados',
                        'icon' => 'Megaphone',
                        'path' => '/config/comunicados',
                        'screen' => 'screen.config.comunicados',
                    ],
                ],
            ],
            [
                'id' => 'admin',
                'label' => 'Administração',
                'icon' => 'Shield',
                'path' => '/admin',
                'screen' => 'screen.admin',
                'children' => [
                    [
                        'id' => 'admin-logs',
                        'label' => 'Logs de Auditoria',
                        'icon' => 'FileSearch',
                        'path' => '/admin/logs',
                        'screen' => 'screen.admin.logs',
                    ],
                    [
                        'id' => 'admin-catalogo',
                        'label' => 'Catálogo de Telefones',
                        'icon' => 'Smartphone',
                        'path' => '/admin/catalogo',
                        'screen' => 'screen.admin.catalogo',
                    ],
                    [
                        'id' => 'admin-whatsapp',
                        'label' => 'WhatsApp',
                        'icon' => 'MessageCircle',
                        'path' => '/admin/whatsapp',
                        'screen' => 'screen.admin.whatsapp',
                    ],
                    [
                        'id' => 'admin-roles',
                        'label' => 'Roles',
                        'icon' => 'UserCog',
                        'path' => '/admin/roles',
                        'screen' => 'screen.admin.roles',
                    ],
                    [
                        'id' => 'admin-permissions',
                        'label' => 'Permissões',
                        'icon' => 'Key',
                        'path' => '/admin/permissions',
                        'screen' => 'screen.admin.permissions',
                    ],
                ],
            ],
        ];
    }
}
