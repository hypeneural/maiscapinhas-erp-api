# 🔄 Contrato Backend ↔ Frontend - Sistema de Permissões

> **Data**: 2026-01-16  
> **Versão**: 1.0 FINAL  
> **Status**: APROVADO PARA IMPLEMENTAÇÃO

---

## ✅ Análise do Documento Frontend

O documento do frontend está **excelente** e alinhado com nossa especificação. Aprovamos a abordagem proposta.

### Pontos Fortes
- ✅ Migração gradual (mantém retrocompatibilidade)
- ✅ Separação clara entre `permissions` (abilities) e `screens` (telas)
- ✅ Menu pré-filtrado do backend
- ✅ Catálogo de screens centralizado
- ✅ `PermissionGate` para componentes condicionais

---

## 📋 Contrato Final do `/me`

### Request
```http
GET /api/v1/me
Authorization: Bearer {token}
```

### Response (TypeScript Interface)

```typescript
interface MeResponse {
  data: {
    user: UserData;
    stores: StoreData[];
    permissions: PermissionsData;
    screens: ScreensData;
    features: string[];
    menu: MenuItem[];
    meta: MetaData;
  };
}

interface UserData {
  id: number;
  name: string;
  email: string;
  avatar_url: string | null;
  is_super_admin: boolean;
  has_fabrica_access: boolean;
  whatsapp: string | null;
  birth_date: string | null;
  hire_date: string | null;
  created_at: string;
  
  // Roles do usuário (para display, não para lógica)
  roles: Array<{
    id: number;
    name: string;
    display_name: string;
    level: number;
    store_id: number | null;  // null = global
  }>;
}

interface StoreData {
  id: number;
  name: string;
  city: string | null;
  state: string | null;
  role: string;  // Role do usuário nesta loja
}

interface PermissionsData {
  global: {
    granted: string[];
    denied: string[];
  };
  by_store: Record<string, {
    granted: string[];
    denied: string[];
  }>;
}

interface ScreensData {
  global: string[];
  by_store: Record<string, string[]>;
}

interface MenuItem {
  id: string;
  label: string;
  icon: string;              // Nome do ícone Lucide
  path: string;
  screen: string;
  badge?: {
    type: 'count' | 'dot';
    value?: number;
    color?: 'red' | 'yellow' | 'green' | 'blue';
  };
  children?: MenuItem[];
}

interface MetaData {
  permissions_version: number;     // Incrementa quando permissões mudam
  permissions_loaded_at: string;   // ISO timestamp
}
```

### Exemplo Completo de Response

```json
{
  "data": {
    "user": {
      "id": 42,
      "name": "Maria Silva",
      "email": "maria@loja.com",
      "avatar_url": "https://storage.../avatar.jpg",
      "is_super_admin": false,
      "has_fabrica_access": false,
      "whatsapp": "5548999999999",
      "birth_date": "1990-05-15",
      "hire_date": "2023-01-10",
      "created_at": "2023-01-10T10:00:00Z",
      "roles": [
        {
          "id": 7,
          "name": "vendedor",
          "display_name": "Vendedor",
          "level": 40,
          "store_id": 1
        },
        {
          "id": 4,
          "name": "gerente",
          "display_name": "Gerente",
          "level": 70,
          "store_id": 2
        }
      ]
    },
    
    "stores": [
      { "id": 1, "name": "Loja Centro", "city": "Tijucas", "state": "SC", "role": "vendedor" },
      { "id": 2, "name": "Loja Praia", "city": "Itapema", "state": "SC", "role": "gerente" }
    ],
    
    "permissions": {
      "global": {
        "granted": [
          "dashboard.view",
          "customers.view",
          "customers.create",
          "customers.update",
          "payment-methods.view",
          "pedidos.view",
          "pedidos.create",
          "pedidos.update",
          "pedidos.status.update",
          "capas.view",
          "capas.create",
          "capas.update",
          "capas.status.update",
          "caixa.shift.open",
          "caixa.closing.create"
        ],
        "denied": []
      },
      "by_store": {
        "2": {
          "granted": [
            "pedidos.view-all",
            "pedidos.delete",
            "capas.view-all",
            "capas.delete",
            "capas.send-production",
            "reports.view",
            "reports.sales",
            "reports.ranking",
            "caixa.closing.approve",
            "caixa.closing.reject",
            "users.view"
          ],
          "denied": []
        }
      }
    },
    
    "screens": {
      "global": [
        "screen.dashboard",
        "screen.comunicados",
        "screen.clientes",
        "screen.pedidos",
        "screen.pedidos.list",
        "screen.pedidos.create",
        "screen.capas",
        "screen.capas.list",
        "screen.capas.create",
        "screen.caixa",
        "screen.caixa.shift",
        "screen.caixa.closing",
        "screen.faturamento",
        "screen.faturamento.extrato",
        "screen.faturamento.bonus",
        "screen.faturamento.comissoes"
      ],
      "by_store": {
        "2": [
          "screen.gestao",
          "screen.gestao.ranking",
          "screen.gestao.lojas",
          "screen.gestao.kpis",
          "screen.caixa.approve",
          "screen.config",
          "screen.config.usuarios",
          "screen.capas.production"
        ]
      }
    },
    
    "features": [
      "feature.whatsapp-notifications"
    ],
    
    "menu": [
      {
        "id": "dashboard",
        "label": "Dashboard",
        "icon": "LayoutDashboard",
        "path": "/",
        "screen": "screen.dashboard"
      },
      {
        "id": "comunicados",
        "label": "Comunicados",
        "icon": "Bell",
        "path": "/comunicados",
        "screen": "screen.comunicados",
        "badge": { "type": "count", "value": 3, "color": "red" }
      },
      {
        "id": "clientes",
        "label": "Clientes",
        "icon": "Users",
        "path": "/clientes",
        "screen": "screen.clientes"
      },
      {
        "id": "pedidos",
        "label": "Pedidos",
        "icon": "FileCheck",
        "path": "/pedidos",
        "screen": "screen.pedidos",
        "children": [
          {
            "id": "pedidos-list",
            "label": "Lista",
            "icon": "List",
            "path": "/pedidos",
            "screen": "screen.pedidos.list"
          },
          {
            "id": "pedidos-new",
            "label": "Novo Pedido",
            "icon": "Plus",
            "path": "/pedidos/new",
            "screen": "screen.pedidos.create"
          }
        ]
      },
      {
        "id": "capas",
        "label": "Capas Personalizadas",
        "icon": "Palette",
        "path": "/capas",
        "screen": "screen.capas"
      },
      {
        "id": "caixa",
        "label": "Caixa",
        "icon": "Wallet",
        "path": "/caixa",
        "screen": "screen.caixa"
      },
      {
        "id": "faturamento",
        "label": "Faturamento",
        "icon": "DollarSign",
        "path": "/faturamento",
        "screen": "screen.faturamento",
        "children": [
          {
            "id": "extrato",
            "label": "Extrato de Vendas",
            "icon": "FileText",
            "path": "/faturamento/extrato",
            "screen": "screen.faturamento.extrato"
          },
          {
            "id": "bonus",
            "label": "Meus Bônus",
            "icon": "Gift",
            "path": "/faturamento/bonus",
            "screen": "screen.faturamento.bonus"
          }
        ]
      },
      {
        "id": "gestao",
        "label": "Gestão",
        "icon": "BarChart3",
        "path": "/gestao",
        "screen": "screen.gestao",
        "children": [
          {
            "id": "ranking",
            "label": "Ranking de Vendas",
            "icon": "Trophy",
            "path": "/gestao/ranking",
            "screen": "screen.gestao.ranking"
          }
        ]
      }
    ],
    
    "meta": {
      "permissions_version": 1,
      "permissions_loaded_at": "2026-01-16T08:30:00Z"
    }
  }
}
```

---

## 📊 Catálogo Completo de Permissions

### Estrutura de Nomenclatura

```
{módulo}.{ação}
{módulo}.{sub-módulo}.{ação}
```

### Módulos Disponíveis

| Módulo | Prefixo | Descrição |
|--------|---------|-----------|
| Dashboard | `dashboard.` | Tela inicial |
| Clientes | `customers.` | CRUD de clientes |
| Formas de Pagamento | `payment-methods.` | CRUD de formas de pagamento |
| Pedidos | `pedidos.` | CRUD de pedidos simples |
| Capas | `capas.` | CRUD de capas personalizadas |
| Caixa | `caixa.` | Turnos e fechamentos |
| Relatórios | `reports.` | Relatórios e analytics |
| Produção | `producao.` | Carrinho e pedidos de produção |
| Fábrica | `fabrica.` | Portal da fábrica |
| Usuários | `users.` | Gerenciamento de usuários |
| Lojas | `stores.` | Gerenciamento de lojas |
| Admin | `admin.` | Funcionalidades administrativas |

### Permissions Detalhadas

```typescript
// lib/permissions/catalog.ts

export const PERMISSION_CATALOG = {
  // ============================================
  // DASHBOARD
  // ============================================
  'dashboard.view': 'Ver dashboard',
  
  // ============================================
  // CLIENTES
  // ============================================
  'customers.view': 'Ver clientes',
  'customers.create': 'Criar cliente',
  'customers.update': 'Editar cliente',
  'customers.delete': 'Excluir cliente',
  'customers.devices.manage': 'Gerenciar dispositivos do cliente',
  
  // ============================================
  // FORMAS DE PAGAMENTO
  // ============================================
  'payment-methods.view': 'Ver formas de pagamento',
  'payment-methods.create': 'Criar forma de pagamento',
  'payment-methods.update': 'Editar forma de pagamento',
  'payment-methods.delete': 'Excluir forma de pagamento',
  
  // ============================================
  // PEDIDOS SIMPLES
  // ============================================
  'pedidos.view': 'Ver pedidos (próprios)',
  'pedidos.view-all': 'Ver todos os pedidos',
  'pedidos.create': 'Criar pedido',
  'pedidos.update': 'Editar pedido',
  'pedidos.delete': 'Excluir pedido',
  'pedidos.status.update': 'Alterar status do pedido',
  'pedidos.bulk-status': 'Alterar status em lote',
  
  // ============================================
  // CAPAS PERSONALIZADAS
  // ============================================
  'capas.view': 'Ver capas (próprias)',
  'capas.view-all': 'Ver todas as capas',
  'capas.create': 'Criar capa',
  'capas.update': 'Editar capa',
  'capas.delete': 'Excluir capa',
  'capas.status.update': 'Alterar status da capa',
  'capas.payment.update': 'Registrar pagamento',
  'capas.photo.upload': 'Fazer upload de foto',
  'capas.send-production': 'Enviar para produção',
  'capas.bulk-status': 'Alterar status em lote',
  
  // ============================================
  // CAIXA
  // ============================================
  'caixa.view': 'Ver fechamentos',
  'caixa.shift.open': 'Abrir turno',
  'caixa.shift.view': 'Ver turnos',
  'caixa.closing.create': 'Fazer fechamento',
  'caixa.closing.update': 'Editar fechamento',
  'caixa.closing.submit': 'Submeter fechamento',
  'caixa.closing.approve': 'Aprovar fechamento',
  'caixa.closing.reject': 'Rejeitar fechamento',
  
  // ============================================
  // RELATÓRIOS
  // ============================================
  'reports.view': 'Ver relatórios',
  'reports.sales': 'Relatório de vendas',
  'reports.ranking': 'Relatório de ranking',
  'reports.performance': 'Relatório de performance',
  'reports.cash-integrity': 'Relatório de integridade de caixa',
  'reports.export': 'Exportar relatórios',
  
  // ============================================
  // FATURAMENTO
  // ============================================
  'faturamento.extrato': 'Ver extrato de vendas',
  'faturamento.bonus': 'Ver bônus',
  'faturamento.bonus.calculate': 'Calcular bônus',
  'faturamento.comissoes': 'Ver comissões',
  'faturamento.comissoes.projection': 'Ver projeção de comissões',
  
  // ============================================
  // PRODUÇÃO
  // ============================================
  'producao.view': 'Ver produção',
  'producao.cart.view': 'Ver carrinho',
  'producao.cart.add': 'Adicionar ao carrinho',
  'producao.cart.remove': 'Remover do carrinho',
  'producao.cart.close': 'Fechar carrinho (enviar pedido)',
  'producao.orders.view': 'Ver pedidos de produção',
  'producao.orders.receive': 'Receber pedido',
  'producao.orders.cancel': 'Cancelar pedido',
  'producao.admin.cleanup': 'Limpar itens órfãos',
  
  // ============================================
  // FÁBRICA
  // ============================================
  'fabrica.view': 'Acessar portal da fábrica',
  'fabrica.orders.view': 'Ver pedidos na fábrica',
  'fabrica.orders.accept': 'Aceitar pedido',
  'fabrica.orders.dispatch': 'Despachar pedido',
  'fabrica.orders.download': 'Baixar fotos',
  
  // ============================================
  // USUÁRIOS
  // ============================================
  'users.view': 'Ver usuários',
  'users.view-all': 'Ver todos os usuários',
  'users.create': 'Criar usuário',
  'users.update': 'Editar usuário',
  'users.delete': 'Excluir usuário',
  'users.stores.manage': 'Gerenciar lojas do usuário',
  'users.roles.manage': 'Gerenciar roles do usuário',
  'users.permissions.manage': 'Gerenciar permissões do usuário',
  
  // ============================================
  // LOJAS
  // ============================================
  'stores.view': 'Ver lojas',
  'stores.view-all': 'Ver todas as lojas',
  'stores.create': 'Criar loja',
  'stores.update': 'Editar loja',
  'stores.delete': 'Excluir loja',
  'stores.users.manage': 'Gerenciar usuários da loja',
  
  // ============================================
  // COMUNICADOS
  // ============================================
  'announcements.view': 'Ver comunicados',
  'announcements.create': 'Criar comunicado',
  'announcements.update': 'Editar comunicado',
  'announcements.delete': 'Excluir comunicado',
  'announcements.publish': 'Publicar comunicado',
  
  // ============================================
  // CONFIGURAÇÕES
  // ============================================
  'config.metas': 'Configurar metas mensais',
  'config.bonus': 'Configurar tabela de bônus',
  'config.comissoes': 'Configurar regras de comissão',
  
  // ============================================
  // ADMIN
  // ============================================
  'admin.audit.view': 'Ver logs de auditoria',
  'admin.catalog.view': 'Ver catálogo de telefones',
  'admin.catalog.manage': 'Gerenciar catálogo de telefones',
  'admin.whatsapp.view': 'Ver instâncias WhatsApp',
  'admin.whatsapp.manage': 'Gerenciar instâncias WhatsApp',
  'admin.roles.view': 'Ver roles',
  'admin.roles.manage': 'Gerenciar roles',
  'admin.permissions.view': 'Ver permissões',
  'admin.permissions.manage': 'Gerenciar permissões',
} as const;

export type Permission = keyof typeof PERMISSION_CATALOG;
```

---

## 📱 Catálogo Completo de Screens

```typescript
// lib/permissions/screens.ts

export const SCREEN_CATALOG = {
  // ============================================
  // PRINCIPAL
  // ============================================
  dashboard: 'screen.dashboard',
  comunicados: 'screen.comunicados',
  
  // ============================================
  // VENDAS / ATENDIMENTO
  // ============================================
  clientes: 'screen.clientes',
  
  pedidos: 'screen.pedidos',
  pedidosList: 'screen.pedidos.list',
  pedidosCreate: 'screen.pedidos.create',
  pedidosDetail: 'screen.pedidos.detail',
  pedidosBulk: 'screen.pedidos.bulk',
  
  capas: 'screen.capas',
  capasList: 'screen.capas.list',
  capasCreate: 'screen.capas.create',
  capasDetail: 'screen.capas.detail',
  capasProduction: 'screen.capas.production',
  capasBulk: 'screen.capas.bulk',
  
  // ============================================
  // CAIXA
  // ============================================
  caixa: 'screen.caixa',
  caixaShift: 'screen.caixa.shift',
  caixaClosing: 'screen.caixa.closing',
  caixaApprove: 'screen.caixa.approve',
  caixaHistory: 'screen.caixa.history',
  
  // ============================================
  // FATURAMENTO (Vendedor)
  // ============================================
  faturamento: 'screen.faturamento',
  faturamentoExtrato: 'screen.faturamento.extrato',
  faturamentoBonus: 'screen.faturamento.bonus',
  faturamentoComissoes: 'screen.faturamento.comissoes',
  
  // ============================================
  // CONFERÊNCIA
  // ============================================
  conferencia: 'screen.conferencia',
  conferenciaLancar: 'screen.conferencia.lancar',
  conferenciaDivergencias: 'screen.conferencia.divergencias',
  conferenciaHistorico: 'screen.conferencia.historico',
  
  // ============================================
  // GESTÃO (Gerente/Admin)
  // ============================================
  gestao: 'screen.gestao',
  gestaoRanking: 'screen.gestao.ranking',
  gestaoLojas: 'screen.gestao.lojas',
  gestaoQuebraCaixa: 'screen.gestao.quebra',
  gestaoKpis: 'screen.gestao.kpis',
  
  // ============================================
  // CONFIGURAÇÕES
  // ============================================
  config: 'screen.config',
  configMetas: 'screen.config.metas',
  configBonus: 'screen.config.bonus',
  configComissoes: 'screen.config.comissoes',
  configUsuarios: 'screen.config.usuarios',
  configComunicados: 'screen.config.comunicados',
  configPaymentMethods: 'screen.config.payment-methods',
  
  // ============================================
  // PRODUÇÃO (Admin)
  // ============================================
  producao: 'screen.producao',
  producaoCarrinho: 'screen.producao.carrinho',
  producaoPedidos: 'screen.producao.pedidos',
  
  // ============================================
  // FÁBRICA
  // ============================================
  fabrica: 'screen.fabrica',
  fabricaPedidos: 'screen.fabrica.pedidos',
  fabricaDespacho: 'screen.fabrica.despacho',
  
  // ============================================
  // ADMIN (Super Admin)
  // ============================================
  admin: 'screen.admin',
  adminLogs: 'screen.admin.logs',
  adminCatalogo: 'screen.admin.catalogo',
  adminWhatsapp: 'screen.admin.whatsapp',
  adminRoles: 'screen.admin.roles',
  adminPermissions: 'screen.admin.permissions',
} as const;

export type Screen = typeof SCREEN_CATALOG[keyof typeof SCREEN_CATALOG];
```

---

## 🎯 Mapeamento Screen → Route

| Screen | Route | Componente |
|--------|-------|------------|
| `screen.dashboard` | `/` | Dashboard |
| `screen.comunicados` | `/comunicados` | Comunicados |
| `screen.clientes` | `/clientes` | CustomerList |
| `screen.pedidos.list` | `/pedidos` | PedidosList |
| `screen.pedidos.create` | `/pedidos/new` | PedidoCreate |
| `screen.pedidos.detail` | `/pedidos/:id` | PedidoDetail |
| `screen.capas.list` | `/capas` | CapasList |
| `screen.capas.create` | `/capas/new` | CapaCreate |
| `screen.capas.production` | `/producao/enviar` | SendToProduction |
| `screen.caixa.shift` | `/caixa` | CaixaShift |
| `screen.caixa.closing` | `/caixa/fechamento` | CaixaClosing |
| `screen.caixa.approve` | `/caixa/aprovar` | CaixaApprove |
| `screen.gestao.ranking` | `/gestao/ranking` | Ranking |
| `screen.gestao.lojas` | `/gestao/lojas` | StorePerformance |
| `screen.config.usuarios` | `/config/usuarios` | UsersConfig |
| `screen.config.payment-methods` | `/config/formas-pagamento` | PaymentMethods |
| `screen.producao.carrinho` | `/producao/carrinho` | ProducaoCarrinho |
| `screen.producao.pedidos` | `/producao/pedidos` | ProducaoPedidos |
| `screen.fabrica.pedidos` | `/fabrica/pedidos` | FabricaPedidos |
| `screen.admin.logs` | `/admin/logs` | AuditLogs |
| `screen.admin.whatsapp` | `/admin/whatsapp` | WhatsAppInstances |

---

## 🔧 Features Disponíveis

| Feature | Descrição | Quem tem por padrão |
|---------|-----------|---------------------|
| `feature.whatsapp-notifications` | Enviar notificações WhatsApp | Admin, Gerente |
| `feature.bulk-operations` | Operações em lote | Admin |
| `feature.export-excel` | Exportar para Excel | Admin, Gerente |
| `feature.advanced-filters` | Filtros avançados | Todos |
| `feature.dark-mode` | Tema escuro | Todos |

---

## 📋 Checklist de Implementação

### Backend (Prioridade Alta)

```
[ ] Fase 1: Migrations e Models
    [ ] Criar tabela `permissions`
    [ ] Criar tabela `roles` (substituir Spatie)
    [ ] Criar tabela `role_permissions`
    [ ] Criar tabela `user_roles`
    [ ] Criar tabela `user_permissions` (overrides)
    [ ] Criar tabela `store_permissions` (overrides)
    [ ] Criar Models e Relationships

[ ] Fase 2: Services
    [ ] Criar `PermissionResolver` service
    [ ] Criar `MenuBuilder` service (monta menu filtrado)
    [ ] Atualizar `User` model com novos helpers

[ ] Fase 3: Seeders
    [ ] Criar seeder com todas as permissions
    [ ] Criar seeder com todos os roles
    [ ] Criar seeder de role_permissions
    [ ] Migrar dados existentes (is_super_admin, StoreUser->role)

[ ] Fase 4: API
    [ ] Atualizar `/me` com novo formato
    [ ] Criar CRUD de roles `/admin/roles`
    [ ] Criar CRUD de permissions `/admin/permissions`
    [ ] Criar endpoints de override por usuário
    [ ] Criar endpoints de override por loja

[ ] Fase 5: Aplicar Middleware
    [ ] Criar middleware `permission:xxx`
    [ ] Aplicar em rotas existentes
```

### Frontend

```
[ ] Fase 1: Preparação
    [ ] Atualizar types com novo formato do /me
    [ ] Adicionar can/canAccessScreen ao AuthContext
    [ ] Criar catálogo de screens

[ ] Fase 2: Implementação
    [ ] Criar componente PermissionGate
    [ ] Atualizar ProtectedRoute
    [ ] Atualizar useFilteredMenu para usar menu do backend
    
[ ] Fase 3: Migração
    [ ] Adicionar screen a cada rota
    [ ] Migrar botões para usar PermissionGate
    [ ] Remover ROLE_PERMISSIONS hardcoded
```

---

## 🚀 Próximos Passos

1. **Backend**: Começar Fase 1 (Migrations e Models)
2. **Frontend**: Começar Fase 1 (Atualizar types)
3. **Reunião de alinhamento** após conclusão da Fase 1

---

*✅ Documento aprovado para implementação - 2026-01-16*
