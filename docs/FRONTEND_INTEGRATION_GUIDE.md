# 🏗️ Guia Completo de Integração Frontend

> **Versão:** 2.0  
> **Data:** 16/01/2026  
> **Para:** Time de Desenvolvimento Frontend

---

## 📚 Índice

1. [Visão Geral da Arquitetura](#1-visão-geral-da-arquitetura)
2. [Fluxo de Autenticação](#2-fluxo-de-autenticação)
3. [Endpoints do Usuário](#3-endpoints-do-usuário)
4. [Sistema de Permissões](#4-sistema-de-permissões)
5. [Sistema Modular](#5-sistema-modular)
6. [Geração de Menus](#6-geração-de-menus)
7. [Renderização de Páginas](#7-renderização-de-páginas)
8. [Boas Práticas](#8-boas-práticas)
9. [Fluxo Completo (Diagrama)](#9-fluxo-completo)
10. [🔐 Super Admin - Gestão de Usuários](#10-super-admin---gestão-de-usuários)
11. [🏪 Super Admin - Gestão de Lojas](#11-super-admin---gestão-de-lojas)
12. [📦 Super Admin - Gestão de Módulos](#12-super-admin---gestão-de-módulos)
13. [🛡️ Super Admin - Gestão de Permissões](#13-super-admin---gestão-de-permissões)
14. [👥 Super Admin - Gestão de Roles](#14-super-admin---gestão-de-roles)

---

## 1. Visão Geral da Arquitetura

```
┌─────────────────────────────────────────────────────────────────┐
│                        FRONTEND                                 │
├─────────────────────────────────────────────────────────────────┤
│  ┌─────────┐   ┌─────────┐   ┌─────────┐   ┌─────────────────┐ │
│  │ Login   │──▶│ /me     │──▶│ /menu   │──▶│ /modules/{id}   │ │
│  │         │   │         │   │         │   │    /full        │ │
│  └─────────┘   └─────────┘   └─────────┘   └─────────────────┘ │
│       │             │             │               │             │
│       ▼             ▼             ▼               ▼             │
│  [Token]      [Permissions] [Menu Items]    [Page Config]       │
└─────────────────────────────────────────────────────────────────┘
```

---

## 2. Fluxo de Autenticação

### POST `/api/v1/auth/login`

**Request:**
```json
{
  "email": "usuario@loja.com",
  "password": "senha123"
}
```

**Response (Sucesso):**
```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGci...",
  "token_type": "bearer",
  "expires_in": 86400,
  "user": {
    "id": 1,
    "name": "João Vendedor",
    "email": "joao@loja.com",
    "is_super_admin": false
  }
}
```

### 🔐 Armazenamento do Token

```typescript
// Armazenar token após login
localStorage.setItem('token', response.access_token);

// Usar em todas as requisições
axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
```

### POST `/api/v1/auth/logout`

Invalida o token atual.

### POST `/api/v1/auth/refresh`

Renova o token antes de expirar.

---

## 3. Endpoints do Usuário

### GET `/api/v1/me`

**Quando chamar:** Imediatamente após login e em cada refresh da página.

**Response:**
```json
{
  "id": 1,
  "name": "João Vendedor",
  "email": "joao@loja.com",
  "is_super_admin": false,
  "photo_url": "https://...",
  
  "current_store": {
    "id": 5,
    "name": "Loja Shopping",
    "slug": "loja-shopping"
  },
  
  "stores": [
    { "id": 5, "name": "Loja Shopping", "role": "vendedor" },
    { "id": 8, "name": "Loja Centro", "role": "admin" }
  ],
  
  "roles": ["vendedor"],
  
  "permissions": [
    "pedidos.view",
    "pedidos.create",
    "pedidos.status.to-aguardando",
    "capas.view",
    "capas.create",
    "screen.pedidos",
    "screen.capas"
  ],
  
  "dashboard_layout": {
    "widgets": ["stats", "recent_orders", "notifications"]
  },
  
  "temporary_permissions": [
    {
      "permission": "capas.view-global",
      "expires_at": "2026-01-20T23:59:59Z",
      "granted_by": "Admin Maria"
    }
  ],
  
  "expiring_soon": [
    {
      "permission": "capas.view-global",
      "expires_in_hours": 72
    }
  ]
}
```

### 🔄 Fluxo Recomendado

```typescript
// 1. Após login, buscar dados do usuário
const user = await api.get('/me');

// 2. Armazenar em contexto global
setUser(user);
setPermissions(user.permissions);
setCurrentStore(user.current_store);

// 3. Verificar permissões antes de renderizar
const canViewPedidos = permissions.includes('screen.pedidos');
```

---

## 4. Sistema de Permissões

### Tipos de Permissões

| Prefixo | Tipo | Descrição |
|---------|------|-----------|
| `screen.*` | Tela | Acesso a páginas/menus |
| `*.view` | Visualização | Ver dados |
| `*.create` | Criação | Criar novos itens |
| `*.update` | Edição | Editar itens |
| `*.delete` | Exclusão | Excluir itens |
| `*.status.*` | Transição | Mudar status |
| `*.view-all` | Escopo Loja | Ver todos da loja |
| `*.view-global` | Escopo Global | Ver de todas lojas |

### GET `/api/v1/admin/users/{id}/permissions/effective`

**Para Super Admin:** Ver permissões resolvidas com fonte.

```json
{
  "permissions": [
    {
      "name": "pedidos.view",
      "source": "role",
      "role": "vendedor"
    },
    {
      "name": "capas.view-global",
      "source": "user_override",
      "granted_by": "Admin Maria",
      "expires_at": "2026-01-20T23:59:59Z"
    },
    {
      "name": "pedidos.export",
      "source": "store_override",
      "store": "Loja Shopping"
    }
  ]
}
```

### Hierarquia de Permissões

```
Super Admin (bypass completo)
    │
    ▼
Role Global (ex: admin)
    │
    ▼
Role por Loja (model_has_roles.store_id)
    │
    ▼
Override por Usuário (permission_user_overrides)
    │
    ▼
Override por Loja (permission_store_overrides)
```

---

## 5. Sistema Modular

### GET `/api/v1/admin/modules`

**Lista todos os módulos disponíveis:**

```json
{
  "data": [
    {
      "id": "pedidos-simples",
      "name": "Pedidos Simples",
      "icon": "FileCheck",
      "is_installed": true,
      "is_active": true,
      "status_count": 6,
      "permission_count": 16,
      "automation_count": 3
    },
    {
      "id": "capas-personalizadas",
      "name": "Capas Personalizadas",
      "icon": "Palette",
      "is_installed": true,
      "is_active": true,
      "status_count": 10,
      "permission_count": 23,
      "automation_count": 2
    }
  ]
}
```

### GET `/api/v1/admin/modules/{id}/full`

**⭐ Endpoint principal para renderização de página.**

```json
{
  "data": {
    "id": "pedidos-simples",
    "name": "Pedidos Simples",
    "icon": "FileCheck",
    "version": "1.0.0",
    
    "texts": {
      "menu_label": "Pedidos",
      "menu_tooltip": "Gerenciar pedidos de encomenda",
      "page_title": "Pedidos de Encomenda",
      "page_description": "Acompanhe todos os pedidos...",
      "create_button": "Novo Pedido",
      "empty_state": "Nenhum pedido encontrado.",
      "loading_title": "Carregando pedidos...",
      "loading_description": "Aguarde enquanto buscamos os pedidos.",
      "error_title": "Erro ao carregar pedidos",
      "error_description": "Não foi possível carregar...",
      "retry_button": "Tentar novamente"
    },
    
    "statuses": {
      "1": {
        "name": "solicitado",
        "label": "Solicitado",
        "description": "Pedido criado, aguardando processamento.",
        "color": "blue",
        "text_color": "white",
        "icon": "clipboard-list",
        "badge_variant": "secondary",
        "can_edit": true,
        "final": false
      }
    },
    
    "actions": {
      "create": {
        "label": "Novo Pedido",
        "icon": "Plus",
        "tooltip": "Criar um novo pedido",
        "shortcut": "N",
        "shortcut_modifier": null,
        "permission": "pedidos.create"
      },
      "avisar_cliente": {
        "label": "Avisar Cliente",
        "icon": "Bell",
        "tooltip": "Enviar WhatsApp...",
        "shortcut": "A",
        "confirm": true,
        "confirm_title": "Avisar Cliente?",
        "confirm_message": "O cliente receberá uma mensagem...",
        "confirm_button": "Sim, Enviar",
        "cancel_button": "Cancelar",
        "confirm_variant": "default",
        "permission": "pedidos.status.to-aguardando",
        "available_in_status": [3]
      },
      "cancelar": {
        "label": "Cancelar",
        "icon": "X",
        "confirm": true,
        "confirm_variant": "destructive",
        "confirm_button": "Sim, Cancelar",
        "permission": "pedidos.cancel",
        "requires_fields": ["cancelation_reason"]
      }
    },
    
    "filters": {
      "status": {
        "type": "multi-select",
        "label": "Status",
        "options": "from_statuses"
      },
      "seller": {
        "type": "select",
        "label": "Vendedor",
        "options": "from_users"
      },
      "date_range": {
        "type": "date-range",
        "label": "Período",
        "presets": ["today", "week", "month", "custom"]
      }
    },
    
    "table_columns": {
      "default": [
        { "key": "id", "label": "#", "sortable": true, "width": 80 },
        { "key": "customer_name", "label": "Cliente", "sortable": true },
        { "key": "status", "label": "Status", "type": "badge" },
        { "key": "created_at", "label": "Data", "type": "date", "format": "dd/MM/yyyy" }
      ]
    },
    
    "bulk_actions": {
      "change_status": {
        "label": "Alterar Status",
        "icon": "RefreshCw",
        "permission": "pedidos.bulk-update"
      },
      "export": {
        "label": "Exportar",
        "icon": "Download",
        "formats": ["xlsx", "pdf", "csv"]
      }
    },
    
    "row_actions": {
      "primary": { "action": "view", "label": "Ver", "icon": "Eye" },
      "secondary": [
        { "action": "edit", "label": "Editar", "icon": "Edit" },
        { "action": "cancel", "label": "Cancelar", "icon": "X", "variant": "destructive" }
      ]
    },
    
    "notifications": {
      "created": {
        "title": "Pedido criado!",
        "description": "Pedido #{id} foi criado.",
        "variant": "success"
      },
      "status_changed": {
        "title": "Status alterado",
        "description": "Pedido #{id} agora está {status}.",
        "variant": "info"
      }
    },
    
    "stats_cards": {
      "enabled": true,
      "permission": "pedidos.view-stats",
      "cards": [
        { "id": "total", "label": "Total", "icon": "Package", "color": "blue" },
        { "id": "pending", "label": "Pendentes", "icon": "Clock", "color": "yellow" }
      ]
    },
    
    "transitions": {
      "1": [2, 3, 6],
      "3": [4, 6]
    },
    
    "transition_role_matrix": {
      "1": {
        "3": ["admin", "gerente"],
        "6": ["vendedor", "admin"]
      }
    },
    
    "permission_groups": {
      "visualizacao": {
        "label": "Visualização",
        "icon": "Eye",
        "permissions": ["pedidos.view", "pedidos.view-all"]
      }
    },
    
    "conditional_fields": {
      "cancelado": {
        "cancelation_reason": {
          "type": "select",
          "label": "Motivo",
          "required": true,
          "options": [
            { "value": "customer_request", "label": "Solicitação do cliente" }
          ]
        }
      }
    },
    
    "documentation": {
      "overview": "O módulo gerencia encomendas...",
      "workflow": {
        "title": "Fluxo do Pedido",
        "steps": ["1. Vendedor cria", "2. Admin disponibiliza"]
      },
      "faq": {
        "Como cancelar?": "Clique em Cancelar..."
      }
    }
  }
}
```

---

## 6. Geração de Menus

### GET `/api/v1/me/menu`

**Retorna menu filtrado por permissões do usuário:**

```json
{
  "menu": [
    {
      "id": "dashboard",
      "label": "Dashboard",
      "icon": "LayoutDashboard",
      "path": "/",
      "permission": null
    },
    {
      "id": "pedidos",
      "label": "Pedidos",
      "icon": "FileCheck",
      "path": "/pedidos",
      "permission": "screen.pedidos",
      "tooltip": "Gerenciar pedidos de encomenda"
    },
    {
      "id": "capas",
      "label": "Capas Personalizadas",
      "icon": "Palette",
      "path": "/capas",
      "permission": "screen.capas",
      "children": [
        {
          "id": "capas-list",
          "label": "Lista",
          "path": "/capas",
          "permission": "screen.capas.list"
        },
        {
          "id": "capas-kanban",
          "label": "Kanban",
          "path": "/capas/kanban",
          "permission": "screen.capas.kanban"
        }
      ]
    }
  ]
}
```

### Renderização do Menu

```typescript
// Hook para menu
function useMenu() {
  const { permissions } = useAuth();
  const { data: menu } = useQuery(['menu'], fetchMenu);
  
  // Filtrar por permissões
  const filteredMenu = menu?.filter(item => 
    !item.permission || permissions.includes(item.permission)
  );
  
  return filteredMenu;
}

// Componente
function Sidebar() {
  const menu = useMenu();
  
  return (
    <nav>
      {menu?.map(item => (
        <MenuItem 
          key={item.id}
          icon={item.icon}
          label={item.label}
          path={item.path}
          tooltip={item.tooltip}
          children={item.children}
        />
      ))}
    </nav>
  );
}
```

---

## 7. Renderização de Páginas

### Estratégia Recomendada

```typescript
// hooks/useModule.ts
function useModule(moduleId: string) {
  return useQuery({
    queryKey: ['module', moduleId, 'full'],
    queryFn: () => api.get(`/admin/modules/${moduleId}/full`),
    staleTime: Infinity,  // Cache agressivo
    gcTime: 1000 * 60 * 60 * 24,  // 24h
  });
}

// pages/PedidosPage.tsx
function PedidosPage() {
  const { data: module, isLoading, error } = useModule('pedidos-simples');
  
  if (isLoading) {
    return <LoadingState 
      title={module?.texts.loading_title}
      description={module?.texts.loading_description}
    />;
  }
  
  if (error) {
    return <ErrorState 
      title={module?.texts.error_title}
      description={module?.texts.error_description}
      retryButton={module?.texts.retry_button}
    />;
  }
  
  return (
    <ModulePage
      title={module.texts.page_title}
      description={module.texts.page_description}
      createButton={module.texts.create_button}
      filters={module.filters}
      columns={module.table_columns.default}
      bulkActions={module.bulk_actions}
      rowActions={module.row_actions}
      statsCards={module.stats_cards}
    />
  );
}
```

### Componente Genérico de Página

```typescript
// components/ModulePage.tsx
interface ModulePageProps {
  moduleId: string;
  endpoint: string;  // ex: /api/v1/pedidos
}

function ModulePage({ moduleId, endpoint }: ModulePageProps) {
  const { data: module } = useModule(moduleId);
  const { data: items } = useQuery(['items', endpoint], () => api.get(endpoint));
  const { permissions } = useAuth();
  
  // Filtrar ações por permissão
  const availableActions = Object.entries(module.actions)
    .filter(([_, action]) => 
      !action.permission || permissions.includes(action.permission)
    );
  
  return (
    <Page>
      <PageHeader 
        title={module.texts.page_title}
        description={module.texts.page_description}
      />
      
      {module.stats_cards.enabled && (
        <StatsCards cards={module.stats_cards.cards} />
      )}
      
      <Toolbar>
        <Filters config={module.filters} />
        <BulkActions config={module.bulk_actions} />
        <CreateButton label={module.texts.create_button} />
      </Toolbar>
      
      <DataTable
        columns={module.table_columns.default}
        data={items}
        rowActions={module.row_actions}
        emptyState={module.texts.empty_state}
      />
    </Page>
  );
}
```

### Componente de Ações Dinâmicas

```typescript
// components/ActionButton.tsx
interface ActionButtonProps {
  action: ModuleAction;
  status: number;
  onExecute: () => void;
}

function ActionButton({ action, status, onExecute }: ActionButtonProps) {
  const { permissions } = useAuth();
  
  // Verificar permissão
  if (action.permission && !permissions.includes(action.permission)) {
    return null;
  }
  
  // Verificar status
  if (action.available_in_status && !action.available_in_status.includes(status)) {
    return null;
  }
  
  // Registrar shortcut
  useHotkey(action.shortcut, onExecute, { modifier: action.shortcut_modifier });
  
  const handleClick = () => {
    if (action.confirm) {
      showConfirmDialog({
        title: action.confirm_title,
        message: action.confirm_message,
        confirmText: action.confirm_button,
        cancelText: action.cancel_button,
        variant: action.confirm_variant,
        onConfirm: onExecute
      });
    } else {
      onExecute();
    }
  };
  
  return (
    <Button onClick={handleClick}>
      <Icon name={action.icon} />
      {action.label}
      {action.shortcut && <Kbd>{action.shortcut}</Kbd>}
    </Button>
  );
}
```

---

## 8. Boas Práticas

### ✅ Cache de Módulos

```typescript
// Prefetch on hover
<MenuItem 
  onMouseEnter={() => {
    queryClient.prefetchQuery(['module', 'pedidos-simples', 'full']);
  }}
>
  Pedidos
</MenuItem>
```

### ✅ Verificação de Permissões

```typescript
// Hook reutilizável
function usePermission(permission: string): boolean {
  const { permissions, is_super_admin } = useAuth();
  return is_super_admin || permissions.includes(permission);
}

// Uso
const canEdit = usePermission('pedidos.update');
const canCancel = usePermission('pedidos.cancel');
```

### ✅ Notificações Padronizadas

```typescript
// Usar templates do módulo
function showNotification(module: Module, type: string, data: object) {
  const template = module.notifications[type];
  const title = interpolate(template.title, data);
  const description = interpolate(template.description, data);
  
  toast[template.variant]({ title, description });
}

// Uso
showNotification(module, 'created', { id: 123 });
// → "Pedido #123 foi criado com sucesso."
```

### ✅ Formulários Dinâmicos

```typescript
// Gerar form baseado em conditional_fields
function DynamicForm({ module, status }: Props) {
  const fields = module.conditional_fields[status] || {};
  
  return (
    <Form>
      {Object.entries(fields).map(([key, config]) => (
        <DynamicField 
          key={key}
          name={key}
          type={config.type}
          label={config.label}
          required={config.required}
          options={config.options}
          placeholder={config.placeholder}
        />
      ))}
    </Form>
  );
}
```

---

## 9. Fluxo Completo

```
┌─────────────────────────────────────────────────────────────────┐
│                    FLUXO DE INICIALIZAÇÃO                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  1. LOGIN                                                       │
│     POST /auth/login → token                                    │
│     ↓                                                           │
│  2. BUSCAR USUÁRIO                                              │
│     GET /me → user, permissions, stores                         │
│     ↓                                                           │
│  3. GERAR MENU                                                  │
│     GET /me/menu → menu items (ou gerar do /me)                 │
│     ↓                                                           │
│  4. USUÁRIO ACESSA PÁGINA                                       │
│     GET /admin/modules/{id}/full → page config                  │
│     ↓                                                           │
│  5. BUSCAR DADOS                                                │
│     GET /{module}/items → items list                            │
│     ↓                                                           │
│  6. RENDERIZAR                                                  │
│     - Filtros (module.filters)                                  │
│     - Tabela (module.table_columns)                             │
│     - Ações (module.actions + permissions)                      │
│     - Stats (module.stats_cards)                                │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Diagrama de Sequência

```
┌────────┐     ┌────────┐     ┌────────┐     ┌────────┐
│ User   │     │ Front  │     │  API   │     │  DB    │
└───┬────┘     └───┬────┘     └───┬────┘     └───┬────┘
    │              │              │              │
    │ Login        │              │              │
    │─────────────▶│              │              │
    │              │ POST /login  │              │
    │              │─────────────▶│              │
    │              │              │ Check user   │
    │              │              │─────────────▶│
    │              │              │◀─────────────│
    │              │◀─────────────│              │
    │              │ token        │              │
    │              │              │              │
    │              │ GET /me      │              │
    │              │─────────────▶│              │
    │              │              │ Load perms   │
    │              │              │─────────────▶│
    │              │◀─────────────│              │
    │              │ permissions  │              │
    │              │              │              │
    │ Click menu   │              │              │
    │─────────────▶│              │              │
    │              │ GET /modules │              │
    │              │  /{id}/full  │              │
    │              │─────────────▶│              │
    │              │◀─────────────│              │
    │              │ page config  │              │
    │              │              │              │
    │◀─────────────│              │              │
    │ Render page  │              │              │
```

---

## 📋 Checklist de Implementação Frontend

### Fase 1: Autenticação
- [ ] Implementar login/logout
- [ ] Armazenar token
- [ ] Configurar axios interceptor
- [ ] Criar AuthContext

### Fase 2: Usuário
- [ ] Criar hook `useAuth`
- [ ] Criar hook `usePermission`
- [ ] Implementar GET /me
- [ ] Armazenar permissions

### Fase 3: Menu
- [ ] Criar hook `useMenu`
- [ ] Filtrar por permissões
- [ ] Implementar prefetch on hover

### Fase 4: Módulos
- [ ] Criar hook `useModule`
- [ ] Implementar cache agressivo
- [ ] Criar `ModulePage` genérico

### Fase 5: Componentes
- [ ] `ActionButton` dinâmico
- [ ] `DynamicForm` para conditional_fields
- [ ] `DataTable` configurável
- [ ] `StatsCards` configurável

### Fase 6: UX
- [ ] Implementar shortcuts
- [ ] Usar notifications do módulo
- [ ] Implementar confirm dialogs

---

## 📞 Suporte

Dúvidas sobre a documentação? Entrar em contato com o time de backend.

**Última atualização:** 16/01/2026

---

## 10. 🔐 Super Admin - Gestão de Usuários

> **Permissão necessária:** `is_super_admin = true`

### GET `/api/v1/admin/users`

**Lista todos os usuários do sistema.**

**Query Params:**
- `search`: Busca por nome/email
- `store_id`: Filtrar por loja
- `role`: Filtrar por role
- `per_page`: Paginação (default: 15)

```json
{
  "data": [
    {
      "id": 1,
      "name": "João Vendedor",
      "email": "joao@loja.com",
      "photo_url": "https://...",
      "is_super_admin": false,
      "is_active": true,
      "created_at": "2026-01-10T10:00:00Z",
      "stores": [
        { "id": 5, "name": "Loja Shopping", "role": "vendedor" }
      ],
      "roles_count": 1,
      "permissions_count": 15
    }
  ],
  "meta": {
    "current_page": 1,
    "total": 50,
    "per_page": 15
  }
}
```

### GET `/api/v1/admin/users/{id}`

**Detalhes completos do usuário.**

```json
{
  "id": 1,
  "name": "João Vendedor",
  "email": "joao@loja.com",
  "whatsapp": "+5548999999999",
  "photo_url": "https://...",
  "is_super_admin": false,
  "is_active": true,
  "created_at": "2026-01-10T10:00:00Z",
  "last_login_at": "2026-01-16T08:30:00Z",
  
  "stores": [
    {
      "id": 5,
      "name": "Loja Shopping",
      "role": "vendedor",
      "assigned_at": "2026-01-10T10:00:00Z"
    }
  ],
  
  "roles": [
    {
      "id": 3,
      "name": "vendedor",
      "display_name": "Vendedor",
      "store_id": 5,
      "store_name": "Loja Shopping"
    }
  ],
  
  "permission_overrides": [
    {
      "id": 12,
      "permission": "capas.view-global",
      "type": "grant",
      "expires_at": "2026-01-20T23:59:59Z",
      "granted_by": "Admin Maria",
      "reason": "Cobertura de férias"
    }
  ]
}
```

### POST `/api/v1/admin/users`

**Criar novo usuário.**

```json
{
  "name": "Maria Silva",
  "email": "maria@loja.com",
  "password": "senha123",
  "whatsapp": "+5548988888888",
  "is_active": true,
  "stores": [
    { "store_id": 5, "role": "vendedor" }
  ]
}
```

### PUT `/api/v1/admin/users/{id}`

**Atualizar usuário.**

```json
{
  "name": "Maria Silva Santos",
  "is_active": true,
  "is_super_admin": false
}
```

### DELETE `/api/v1/admin/users/{id}`

**Desativar usuário (soft delete).**

---

## 11. 🏪 Super Admin - Gestão de Lojas

### GET `/api/v1/admin/stores`

**Lista todas as lojas.**

```json
{
  "data": [
    {
      "id": 5,
      "name": "Loja Shopping",
      "slug": "loja-shopping",
      "address": "Rua das Flores, 123",
      "phone": "+5548333333333",
      "is_active": true,
      "users_count": 8,
      "modules": ["pedidos-simples", "capas-personalizadas"]
    }
  ]
}
```

### GET `/api/v1/admin/stores/{id}`

**Detalhes da loja com usuários e módulos.**

```json
{
  "id": 5,
  "name": "Loja Shopping",
  "slug": "loja-shopping",
  "address": "Rua das Flores, 123",
  "phone": "+5548333333333",
  "email": "shopping@loja.com",
  "is_active": true,
  
  "users": [
    {
      "id": 1,
      "name": "João Vendedor",
      "role": "vendedor"
    },
    {
      "id": 2,
      "name": "Maria Admin",
      "role": "admin"
    }
  ],
  
  "modules": [
    {
      "id": "pedidos-simples",
      "name": "Pedidos Simples",
      "is_active": true,
      "activated_at": "2026-01-01T00:00:00Z"
    }
  ],
  
  "permission_overrides": [
    {
      "permission": "pedidos.export",
      "type": "grant",
      "applies_to_all_users": true
    }
  ]
}
```

### POST `/api/v1/admin/stores`

**Criar nova loja.**

```json
{
  "name": "Loja Centro",
  "slug": "loja-centro",
  "address": "Av. Principal, 500",
  "phone": "+5548444444444",
  "email": "centro@loja.com",
  "is_active": true
}
```

### PUT `/api/v1/admin/stores/{id}`

**Atualizar loja.**

### DELETE `/api/v1/admin/stores/{id}`

**Desativar loja (soft delete).**

---

## 12. 📦 Super Admin - Gestão de Módulos

### GET `/api/v1/admin/modules`

**Lista todos os módulos (já documentado na seção 5).**

### GET `/api/v1/admin/modules/{id}`

**Detalhes do módulo.**

### GET `/api/v1/admin/modules/{id}/full`

**Configuração completa para renderização (já documentado).**

### POST `/api/v1/admin/modules/{id}/install`

**Instalar módulo no sistema.**

```json
// Response
{
  "message": "Módulo 'Pedidos Simples' instalado com sucesso.",
  "data": {
    "id": "pedidos-simples",
    "installed_at": "2026-01-16T11:00:00Z"
  }
}
```

### POST `/api/v1/admin/modules/{id}/activate`

**Ativar módulo globalmente.**

### POST `/api/v1/admin/modules/{id}/deactivate`

**Desativar módulo globalmente.**

> ⚠️ Módulos core não podem ser desativados.

### POST `/api/v1/admin/modules/{id}/stores/{storeId}/activate`

**Ativar módulo para uma loja específica.**

```json
// Response
{
  "message": "Módulo ativado para loja #5."
}
```

### POST `/api/v1/admin/modules/{id}/stores/{storeId}/deactivate`

**Desativar módulo para uma loja específica.**

### GET `/api/v1/admin/modules/{id}/transitions`

**Ver matriz de transições de status.**

```json
{
  "module_id": "pedidos-simples",
  "statuses": {
    "1": { "name": "solicitado", "label": "Solicitado" },
    "3": { "name": "disponivel", "label": "Disponível" },
    "6": { "name": "cancelado", "label": "Cancelado" }
  },
  "transitions": {
    "1": [2, 3, 6],
    "3": [4, 6]
  },
  "role_matrix": {
    "1": {
      "3": ["admin", "gerente"],
      "6": ["vendedor", "admin", "gerente"]
    }
  }
}
```

### PUT `/api/v1/admin/modules/{id}/transitions`

**Editar matriz de transições (quem pode fazer qual transição).**

```json
// Request
{
  "transitions": {
    "1": {
      "3": ["admin", "gerente", "conferente"],
      "6": ["admin", "gerente"]
    }
  }
}

// Response
{
  "message": "Matriz de transições atualizada.",
  "data": { ... }
}
```

### 🎨 UI Sugerida: Editor de Transições

```
┌─────────────────────────────────────────────────────────────────┐
│ Módulo: Pedidos Simples - Matriz de Transições                 │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ De ↓ / Para →    │ Disponível │ Aguardando │ Concluído │ Canc. │
│ ─────────────────┼────────────┼────────────┼───────────┼───────│
│ Solicitado       │ [AG]       │ [ ]        │ [ ]       │ [VAG] │
│ Disponível       │ ─          │ [VAG]      │ [AG]      │ [AG]  │
│ Aguardando       │ [ ]        │ ─          │ [VAG]     │ [AG]  │
│ Concluído        │ [ ]        │ [ ]        │ ─         │ [ ]   │
│                                                                 │
│ Legenda: V=Vendedor  A=Admin  G=Gerente  S=Super                │
│                                                                 │
│ [Salvar Alterações]                                             │
└─────────────────────────────────────────────────────────────────┘
```

---

## 13. 🛡️ Super Admin - Gestão de Permissões

### GET `/api/v1/admin/permissions`

**Lista todas as permissões do sistema.**

```json
{
  "data": [
    {
      "id": 1,
      "name": "pedidos.view",
      "display_name": "Ver pedidos (próprios)",
      "type": "ability",
      "module": "pedidos-simples",
      "group": "visualizacao"
    },
    {
      "id": 2,
      "name": "screen.pedidos",
      "display_name": "Menu Pedidos",
      "type": "screen",
      "module": "pedidos-simples",
      "group": null
    }
  ],
  "groups": {
    "visualizacao": {
      "label": "Visualização",
      "icon": "Eye"
    },
    "gerenciamento": {
      "label": "Gerenciamento",
      "icon": "Edit"
    }
  }
}
```

### GET `/api/v1/admin/users/{userId}/permissions`

**Ver permissões de um usuário.**

```json
{
  "user_id": 1,
  "user_name": "João Vendedor",
  "permissions": [
    "pedidos.view",
    "pedidos.create",
    "screen.pedidos"
  ],
  "overrides": [
    {
      "id": 12,
      "permission": "capas.view-global",
      "type": "grant",
      "expires_at": "2026-01-20T23:59:59Z"
    }
  ]
}
```

### POST `/api/v1/admin/users/{userId}/permissions`

**Adicionar override de permissão para usuário.**

```json
// Request
{
  "permission": "capas.view-global",
  "type": "grant",
  "expires_at": "2026-01-20T23:59:59Z",
  "reason": "Cobertura de férias"
}

// Response
{
  "message": "Permissão concedida.",
  "data": {
    "id": 15,
    "permission": "capas.view-global",
    "type": "grant",
    "expires_at": "2026-01-20T23:59:59Z"
  }
}
```

### DELETE `/api/v1/admin/users/{userId}/permissions/{overrideId}`

**Remover override de permissão.**

### GET `/api/v1/admin/users/{userId}/permissions/effective`

**Ver permissões efetivas com fonte de cada uma.**

```json
{
  "user_id": 1,
  "permissions": [
    {
      "name": "pedidos.view",
      "display_name": "Ver pedidos",
      "source": "role",
      "role": "vendedor",
      "store": "Loja Shopping"
    },
    {
      "name": "capas.view-global",
      "display_name": "Ver todas as capas",
      "source": "user_override",
      "granted_by": "Admin Maria",
      "expires_at": "2026-01-20T23:59:59Z",
      "reason": "Cobertura de férias"
    },
    {
      "name": "pedidos.export",
      "display_name": "Exportar pedidos",
      "source": "store_override",
      "store": "Loja Shopping"
    }
  ]
}
```

### POST `/api/v1/admin/stores/{storeId}/permissions`

**Adicionar override de permissão para loja (todos os usuários).**

```json
// Request
{
  "permission": "pedidos.export",
  "type": "grant"
}
```

### 🎨 UI Sugerida: Editor de Permissões

```
┌─────────────────────────────────────────────────────────────────┐
│ Permissões: João Vendedor                                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ ┌─ Pedidos Simples ────────────────────────────────────────────┐│
│ │ ✅ pedidos.view          (via role: vendedor)                ││
│ │ ✅ pedidos.create        (via role: vendedor)                ││
│ │ ❌ pedidos.view-all      [+ Conceder]                        ││
│ │ ✅ pedidos.export        (via loja: Shopping) [Revogar]      ││
│ └──────────────────────────────────────────────────────────────┘│
│                                                                 │
│ ┌─ Capas Personalizadas ───────────────────────────────────────┐│
│ │ ✅ capas.view            (via role: vendedor)                ││
│ │ ⏰ capas.view-global     (override, expira 20/01) [Revogar]  ││
│ └──────────────────────────────────────────────────────────────┘│
│                                                                 │
│ [+ Adicionar Override Temporário]                               │
└─────────────────────────────────────────────────────────────────┘
```

---

## 14. 👥 Super Admin - Gestão de Roles

### GET `/api/v1/admin/roles`

**Lista todas as roles do sistema.**

```json
{
  "data": [
    {
      "id": 1,
      "name": "super-admin",
      "display_name": "Super Administrador",
      "description": "Acesso total ao sistema",
      "is_system": true,
      "permissions_count": 180,
      "users_count": 2
    },
    {
      "id": 2,
      "name": "admin",
      "display_name": "Administrador",
      "description": "Administrador de loja",
      "is_system": true,
      "permissions_count": 45,
      "users_count": 10
    },
    {
      "id": 3,
      "name": "vendedor",
      "display_name": "Vendedor",
      "description": "Vendedor de loja",
      "is_system": true,
      "permissions_count": 15,
      "users_count": 50
    }
  ]
}
```

### GET `/api/v1/admin/roles/{id}`

**Detalhes da role com permissões.**

```json
{
  "id": 3,
  "name": "vendedor",
  "display_name": "Vendedor",
  "description": "Vendedor de loja",
  "is_system": true,
  
  "permissions": [
    { "name": "pedidos.view", "display_name": "Ver pedidos" },
    { "name": "pedidos.create", "display_name": "Criar pedidos" },
    { "name": "screen.pedidos", "display_name": "Menu Pedidos" }
  ],
  
  "users": [
    { "id": 1, "name": "João Vendedor", "store": "Loja Shopping" },
    { "id": 3, "name": "Pedro Silva", "store": "Loja Centro" }
  ]
}
```

### POST `/api/v1/admin/roles`

**Criar nova role customizada.**

```json
// Request
{
  "name": "conferente",
  "display_name": "Conferente",
  "description": "Confere estoque e pedidos",
  "permissions": [
    "pedidos.view-all",
    "pedidos.status.to-disponivel",
    "screen.pedidos"
  ]
}
```

### PUT `/api/v1/admin/roles/{id}`

**Atualizar role (apenas roles não-system).**

### DELETE `/api/v1/admin/roles/{id}`

**Excluir role customizada.**

### POST `/api/v1/admin/users/{userId}/roles`

**Atribuir role a um usuário.**

```json
// Request
{
  "role_id": 3,
  "store_id": 5
}

// Response
{
  "message": "Role 'vendedor' atribuída ao usuário na loja 'Loja Shopping'.",
  "data": {
    "user_id": 1,
    "role_id": 3,
    "store_id": 5,
    "assigned_at": "2026-01-16T11:30:00Z"
  }
}
```

### DELETE `/api/v1/admin/users/{userId}/roles/{assignmentId}`

**Remover role de um usuário.**

### 🎨 UI Sugerida: Atribuição de Roles

```
┌─────────────────────────────────────────────────────────────────┐
│ Roles: João Vendedor                                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ Roles Atuais:                                                   │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ 🏷️ Vendedor @ Loja Shopping           [Remover]             ││
│ │    Desde: 10/01/2026                                         ││
│ └──────────────────────────────────────────────────────────────┘│
│                                                                 │
│ Adicionar Role:                                                 │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ Role: [vendedor ▼]                                           ││
│ │ Loja: [Loja Centro ▼]                                        ││
│ │ [Atribuir]                                                   ││
│ └──────────────────────────────────────────────────────────────┘│
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## 📋 Resumo de Endpoints Super Admin

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| **Usuários** | | |
| GET | `/admin/users` | Listar usuários |
| GET | `/admin/users/{id}` | Detalhes do usuário |
| POST | `/admin/users` | Criar usuário |
| PUT | `/admin/users/{id}` | Atualizar usuário |
| DELETE | `/admin/users/{id}` | Desativar usuário |
| **Lojas** | | |
| GET | `/admin/stores` | Listar lojas |
| GET | `/admin/stores/{id}` | Detalhes da loja |
| POST | `/admin/stores` | Criar loja |
| PUT | `/admin/stores/{id}` | Atualizar loja |
| DELETE | `/admin/stores/{id}` | Desativar loja |
| **Módulos** | | |
| GET | `/admin/modules` | Listar módulos |
| GET | `/admin/modules/{id}/full` | Config completa |
| POST | `/admin/modules/{id}/install` | Instalar |
| POST | `/admin/modules/{id}/activate` | Ativar global |
| POST | `/admin/modules/{id}/deactivate` | Desativar global |
| GET | `/admin/modules/{id}/transitions` | Ver transições |
| PUT | `/admin/modules/{id}/transitions` | Editar transições |
| **Permissões** | | |
| GET | `/admin/permissions` | Listar permissões |
| GET | `/admin/users/{id}/permissions` | Permissões do user |
| POST | `/admin/users/{id}/permissions` | Add override |
| DELETE | `/admin/users/{id}/permissions/{id}` | Remove override |
| GET | `/admin/users/{id}/permissions/effective` | Permissões + fonte |
| **Roles** | | |
| GET | `/admin/roles` | Listar roles |
| GET | `/admin/roles/{id}` | Detalhes da role |
| POST | `/admin/roles` | Criar role |
| PUT | `/admin/roles/{id}` | Atualizar role |
| DELETE | `/admin/roles/{id}` | Excluir role |
| POST | `/admin/users/{id}/roles` | Atribuir role |
| DELETE | `/admin/users/{id}/roles/{id}` | Remover role |

---

## 📞 Suporte

Dúvidas sobre a documentação? Entrar em contato com o time de backend.

**Última atualização:** 16/01/2026
