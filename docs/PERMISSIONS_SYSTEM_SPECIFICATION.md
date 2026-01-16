# 🔐 Sistema de Permissões Granular - Especificação Técnica

> **Data**: 2026-01-16  
> **Status**: Especificação Detalhada  
> **Versão**: 2.0

---

## 📋 Índice

1. [Visão Geral](#visão-geral)
2. [Arquitetura de Permissões](#arquitetura-de-permissões)
3. [Níveis de Atribuição](#níveis-de-atribuição)
4. [Sistema de Menus e Telas](#sistema-de-menus-e-telas)
5. [Modelo de Dados](#modelo-de-dados)
6. [Endpoints da API](#endpoints-da-api)
7. [Lógica de Resolução](#lógica-de-resolução)
8. [Resposta do /me](#resposta-do-me)
9. [Implementação](#implementação)

---

## Visão Geral

### Objetivo

Criar um sistema de permissões que permita:

1. **Granularidade total** - Controlar acesso a cada endpoint/ação
2. **Múltiplos níveis** - Role → Loja → Usuário (com override)
3. **Controle de telas** - Definir quais menus/páginas cada usuário vê
4. **Flexibilidade** - Dar permissão especial a usuário específico
5. **Facilidade para o frontend** - Retornar tudo pronto no `/me`

### Princípio de Herança

```
┌─────────────────────────────────────────────────────────────────┐
│                         HIERARQUIA                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│   ROLE (Global)                                                 │
│      │                                                          │
│      ▼                                                          │
│   LOJA (Override por loja)                                      │
│      │                                                          │
│      ▼                                                          │
│   USUÁRIO (Override específico)                                 │
│                                                                 │
│   Regra: Nível mais específico sempre vence                     │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Exemplo:**
- Role `vendedor` **não pode** acessar relatórios
- Loja "Centro" libera relatórios para **todos os vendedores** dela
- Usuário "Maria" (vendedora de outra loja) tem acesso **individual** a relatórios

---

## Arquitetura de Permissões

### Tipos de Permissão

```
┌─────────────────────────────────────────────────────────────────┐
│                    TIPOS DE PERMISSÃO                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  1. ABILITY (Ações em recursos)                                 │
│     └─ payment-methods.create                                   │
│     └─ pedidos.update                                           │
│     └─ users.delete                                             │
│                                                                 │
│  2. SCREEN (Telas/Menus)                                        │
│     └─ screen.dashboard                                         │
│     └─ screen.pedidos                                           │
│     └─ screen.admin.users                                       │
│                                                                 │
│  3. FEATURE (Funcionalidades especiais)                         │
│     └─ feature.whatsapp-notifications                           │
│     └─ feature.export-excel                                     │
│     └─ feature.bulk-operations                                  │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Níveis de Atribuição

### Nível 1: Role (Global)

Permissões padrão para todos os usuários com aquele role.

```php
// Todos os vendedores podem:
Role::vendedor->permissions = [
    'payment-methods.view',
    'pedidos.view',
    'pedidos.create',
    'screen.dashboard',
    'screen.pedidos',
];
```

### Nível 2: Loja (Store Override)

Permissões extras ou restrições para todos os usuários de uma loja.

```php
// Loja Centro dá permissões extras a TODOS os funcionários:
Store::Centro->extra_permissions = [
    'reports.view',           // Libera relatórios
    'feature.export-excel',   // Libera exportação
];

// Loja Itapema RESTRINGE algumas permissões:
Store::Itapema->denied_permissions = [
    'pedidos.delete',  // Ninguém pode excluir pedidos nesta loja
];
```

### Nível 3: Usuário (User Override)

Permissões específicas para um usuário individual.

```php
// Maria (vendedora) recebe permissão especial:
User::Maria->extra_permissions = [
    'admin.users.view',      // Pode ver usuários (normalmente só admin)
    'screen.admin.users',    // Vê o menu de usuários
];

// João (admin) tem uma restrição específica:
User::Joao->denied_permissions = [
    'users.delete',  // Mesmo sendo admin, não pode excluir usuários
];
```

---

## Sistema de Menus e Telas

### Estrutura de Telas

```
MENU LATERAL
├── 📊 Dashboard                    screen.dashboard
│
├── 📦 Pedidos                      screen.pedidos
│   ├── Lista de Pedidos           screen.pedidos.list
│   ├── Novo Pedido                screen.pedidos.create
│   └── Bulk Actions               screen.pedidos.bulk
│
├── 🎨 Capas Personalizadas         screen.capas
│   ├── Lista                      screen.capas.list
│   ├── Nova Capa                  screen.capas.create
│   └── Enviar Produção            screen.capas.production
│
├── 💰 Caixa                        screen.caixa
│   ├── Meu Turno                  screen.caixa.shift
│   ├── Fechamento                 screen.caixa.closing
│   └── Aprovar Fechamentos        screen.caixa.approve
│
├── 📊 Relatórios                   screen.reports
│   ├── Vendas                     screen.reports.sales
│   ├── Ranking                    screen.reports.ranking
│   └── Performance                screen.reports.performance
│
├── 🏭 Produção                     screen.producao
│   ├── Carrinho                   screen.producao.cart
│   └── Pedidos                    screen.producao.orders
│
├── 🏭 Fábrica                      screen.fabrica
│   ├── Pedidos                    screen.fabrica.orders
│   └── Despacho                   screen.fabrica.dispatch
│
├── ⚙️ Configurações                screen.settings
│   ├── Formas de Pagamento        screen.settings.payment-methods
│   ├── Marcas                     screen.settings.brands
│   └── Modelos                    screen.settings.models
│
└── 👤 Administração                screen.admin
    ├── Usuários                   screen.admin.users
    ├── Lojas                      screen.admin.stores
    ├── Roles                      screen.admin.roles
    ├── Permissões                 screen.admin.permissions
    ├── WhatsApp                   screen.admin.whatsapp
    └── Logs                       screen.admin.logs
```

### Tabela de Screens por Role

| Screen | Super Admin | Admin | Fábrica | Gerente | Conferente | Estoquista | Vendedor |
|--------|-------------|-------|---------|---------|------------|------------|----------|
| `screen.dashboard` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `screen.pedidos` | ✅ | ✅ | ❌ | ✅ | ✅ | 👁️ | ✅ |
| `screen.pedidos.bulk` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `screen.capas` | ✅ | ✅ | 👁️ | ✅ | ✅ | 👁️ | ✅ |
| `screen.capas.production` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `screen.caixa` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ | ✅ |
| `screen.caixa.approve` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ | ❌ |
| `screen.reports` | ✅ | ✅ | ❌ | ✅ | 👁️ | ❌ | ❌ |
| `screen.producao` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `screen.fabrica` | ✅ | 👁️ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `screen.settings` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `screen.admin` | ✅ | ✅* | ❌ | ❌ | ❌ | ❌ | ❌ |
| `screen.admin.whatsapp` | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `screen.admin.roles` | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

*Admin vê apenas usuários/lojas que gerencia

---

## Modelo de Dados

### Diagrama ER

```
┌─────────────────┐
│     roles       │
│─────────────────│
│ id              │
│ name            │◄─────────────────────────────────────┐
│ display_name    │                                      │
│ level           │      ┌─────────────────┐             │
│ is_system       │      │ role_permissions│             │
└─────────────────┘      │─────────────────│             │
                         │ role_id ────────┼─────────────┘
                         │ permission_id ──┼─────────────┐
                         └─────────────────┘             │
                                                         │
┌─────────────────┐                              ┌───────▼─────────┐
│   permissions   │                              │   permissions   │
│─────────────────│                              │─────────────────│
│ id              │                              │ id              │
│ name            │ (unique)                     │ name            │
│ display_name    │                              │ type            │ (ability/screen/feature)
│ type            │                              │ module          │
│ module          │                              │ description     │
│ description     │                              └─────────────────┘
└─────────────────┘                                      ▲
                                                         │
┌─────────────────┐      ┌─────────────────┐             │
│     users       │      │ user_permissions│             │
│─────────────────│      │─────────────────│             │
│ id              │◄─────┤ user_id         │             │
│ name            │      │ permission_id ──┼─────────────┤
│ email           │      │ store_id (null) │ (global ou por loja)
│ ...             │      │ granted         │ (true=libera, false=nega)
└─────────────────┘      │ expires_at      │ (opcional)
        │                └─────────────────┘
        │
        │                ┌─────────────────┐
        │                │store_permissions│
        │                │─────────────────│
        └───────────────►│ store_id        │
                         │ permission_id ──┼─────────────┘
                         │ granted         │
                         └─────────────────┘

┌─────────────────┐
│     stores      │
│─────────────────│◄────── store_permissions.store_id
│ id              │
│ name            │
└─────────────────┘
```

### Migrations

#### 1. Tabela `permissions`

```php
Schema::create('permissions', function (Blueprint $table) {
    $table->id();
    $table->string('name', 100)->unique();  // ex: pedidos.create
    $table->string('display_name', 150);     // ex: Criar Pedidos
    $table->enum('type', ['ability', 'screen', 'feature'])->default('ability');
    $table->string('module', 50)->nullable(); // ex: pedidos, capas, admin
    $table->text('description')->nullable();
    $table->integer('sort_order')->default(0);
    $table->timestamps();
    
    $table->index(['type', 'module']);
});
```

#### 2. Tabela `roles`

```php
Schema::create('roles', function (Blueprint $table) {
    $table->id();
    $table->string('name', 50)->unique();      // ex: vendedor
    $table->string('display_name', 100);        // ex: Vendedor
    $table->text('description')->nullable();
    $table->integer('level')->default(0);       // Hierarquia (maior = mais poder)
    $table->boolean('is_system')->default(false); // Roles que não podem ser excluídos
    $table->timestamps();
});
```

#### 3. Tabela `role_permissions`

```php
Schema::create('role_permissions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('role_id')->constrained()->cascadeOnDelete();
    $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
    $table->timestamps();
    
    $table->unique(['role_id', 'permission_id']);
});
```

#### 4. Tabela `user_roles`

```php
Schema::create('user_roles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('role_id')->constrained()->cascadeOnDelete();
    $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
    // store_id = null significa role global
    // store_id = X significa role apenas naquela loja
    $table->timestamps();
    
    $table->unique(['user_id', 'role_id', 'store_id']);
});
```

#### 5. Tabela `user_permissions` (Overrides de usuário)

```php
Schema::create('user_permissions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
    $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
    // store_id = null significa override global
    // store_id = X significa override apenas naquela loja
    $table->boolean('granted')->default(true);  // true=libera, false=nega
    $table->timestamp('expires_at')->nullable(); // Permissão temporária
    $table->foreignId('granted_by')->nullable()->constrained('users');
    $table->text('reason')->nullable();
    $table->timestamps();
    
    $table->unique(['user_id', 'permission_id', 'store_id']);
});
```

#### 6. Tabela `store_permissions` (Overrides de loja)

```php
Schema::create('store_permissions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('store_id')->constrained()->cascadeOnDelete();
    $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
    $table->boolean('granted')->default(true);  // true=libera extra, false=nega
    $table->timestamps();
    
    $table->unique(['store_id', 'permission_id']);
});
```

---

## Endpoints da API

### Permissões

```
GET    /api/v1/admin/permissions                    # Listar todas as permissões
GET    /api/v1/admin/permissions/grouped            # Listar agrupadas por módulo
POST   /api/v1/admin/permissions                    # Criar permissão
GET    /api/v1/admin/permissions/{id}               # Detalhes
PATCH  /api/v1/admin/permissions/{id}               # Atualizar
DELETE /api/v1/admin/permissions/{id}               # Excluir
```

### Roles

```
GET    /api/v1/admin/roles                          # Listar roles
POST   /api/v1/admin/roles                          # Criar role
GET    /api/v1/admin/roles/{id}                     # Detalhes (inclui permissions)
PATCH  /api/v1/admin/roles/{id}                     # Atualizar
DELETE /api/v1/admin/roles/{id}                     # Excluir

# Permissões do Role
GET    /api/v1/admin/roles/{id}/permissions         # Listar permissions do role
PUT    /api/v1/admin/roles/{id}/permissions         # Sincronizar (substitui todas)
POST   /api/v1/admin/roles/{id}/permissions         # Adicionar permissions
DELETE /api/v1/admin/roles/{id}/permissions         # Remover permissions
```

### Usuários - Roles

```
GET    /api/v1/admin/users/{id}/roles               # Listar roles do usuário
POST   /api/v1/admin/users/{id}/roles               # Atribuir role
DELETE /api/v1/admin/users/{id}/roles/{roleId}      # Remover role

# Atribuir role em loja específica
POST   /api/v1/admin/users/{id}/roles
Body: { "role_id": 3, "store_id": 5 }  // Gerente apenas na loja 5
```

### Usuários - Permissões Especiais

```
GET    /api/v1/admin/users/{id}/permissions         # Listar overrides
POST   /api/v1/admin/users/{id}/permissions         # Adicionar override
DELETE /api/v1/admin/users/{id}/permissions/{permId} # Remover override

# Exemplo: Dar permissão especial
POST   /api/v1/admin/users/{id}/permissions
Body: {
    "permission_id": 15,          // reports.view
    "store_id": null,             // global (ou ID da loja)
    "granted": true,              // liberar (ou false para negar)
    "expires_at": "2026-02-01",   // opcional - temporário
    "reason": "Projeto especial"  // opcional - auditoria
}
```

### Lojas - Permissões

```
GET    /api/v1/admin/stores/{id}/permissions        # Listar overrides da loja
POST   /api/v1/admin/stores/{id}/permissions        # Adicionar override
DELETE /api/v1/admin/stores/{id}/permissions/{permId} # Remover override

# Exemplo: Liberar relatórios para toda a loja
POST   /api/v1/admin/stores/{id}/permissions
Body: { "permission_id": 15, "granted": true }
```

---

## Lógica de Resolução

### Algoritmo de Verificação

```php
class PermissionResolver
{
    /**
     * Verifica se o usuário tem uma permissão.
     * 
     * @param User $user
     * @param string $permission Nome da permissão (ex: "pedidos.create")
     * @param int|null $storeId Contexto de loja (opcional)
     * @return bool
     */
    public function check(User $user, string $permission, ?int $storeId = null): bool
    {
        // 1. Super Admin tem tudo
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // 2. Verificar override de USUÁRIO (maior prioridade)
        $userOverride = $this->getUserOverride($user, $permission, $storeId);
        if ($userOverride !== null) {
            return $userOverride->granted;
        }

        // 3. Verificar override de LOJA (se houver contexto de loja)
        if ($storeId) {
            $storeOverride = $this->getStoreOverride($storeId, $permission);
            if ($storeOverride !== null) {
                return $storeOverride->granted;
            }
        }

        // 4. Verificar permissões do ROLE
        $roles = $this->getUserRoles($user, $storeId);
        foreach ($roles as $role) {
            if ($role->permissions->contains('name', $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retorna todas as permissões efetivas do usuário.
     */
    public function getAllPermissions(User $user, ?int $storeId = null): array
    {
        $permissions = [];

        // 1. Coletar de roles
        $roles = $this->getUserRoles($user, $storeId);
        foreach ($roles as $role) {
            foreach ($role->permissions as $perm) {
                $permissions[$perm->name] = true;
            }
        }

        // 2. Aplicar overrides de loja
        if ($storeId) {
            $storeOverrides = StorePermission::where('store_id', $storeId)->get();
            foreach ($storeOverrides as $override) {
                $permissions[$override->permission->name] = $override->granted;
            }
        }

        // 3. Aplicar overrides de usuário (maior prioridade)
        $userOverrides = UserPermission::where('user_id', $user->id)
            ->where(function ($q) use ($storeId) {
                $q->whereNull('store_id')
                  ->orWhere('store_id', $storeId);
            })
            ->get();
        
        foreach ($userOverrides as $override) {
            $permissions[$override->permission->name] = $override->granted;
        }

        // 4. Filtrar apenas as granted=true
        return array_keys(array_filter($permissions));
    }
}
```

### Diagrama de Fluxo

```
┌─────────────────────────────────────────────────────────────────┐
│                    VERIFICAÇÃO DE PERMISSÃO                     │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│   Usuário quer acessar: "pedidos.delete" na Loja 5              │
│                                                                 │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │ 1. É Super Admin?                                       │   │
│   │    SIM → ✅ PERMITIDO                                   │   │
│   │    NÃO → Continua...                                    │   │
│   └─────────────────────────────────────────────────────────┘   │
│                          │                                      │
│                          ▼                                      │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │ 2. Tem override de USUÁRIO para "pedidos.delete"?       │   │
│   │    - Global (store_id = null)?                          │   │
│   │    - Específico (store_id = 5)?                         │   │
│   │                                                         │   │
│   │    SIM + granted=true  → ✅ PERMITIDO                   │   │
│   │    SIM + granted=false → ❌ NEGADO                      │   │
│   │    NÃO → Continua...                                    │   │
│   └─────────────────────────────────────────────────────────┘   │
│                          │                                      │
│                          ▼                                      │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │ 3. Tem override de LOJA 5 para "pedidos.delete"?        │   │
│   │                                                         │   │
│   │    SIM + granted=true  → ✅ PERMITIDO                   │   │
│   │    SIM + granted=false → ❌ NEGADO                      │   │
│   │    NÃO → Continua...                                    │   │
│   └─────────────────────────────────────────────────────────┘   │
│                          │                                      │
│                          ▼                                      │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │ 4. Role do usuário tem "pedidos.delete"?                │   │
│   │    - Role global do usuário                             │   │
│   │    - Role na Loja 5                                     │   │
│   │                                                         │   │
│   │    SIM → ✅ PERMITIDO                                   │   │
│   │    NÃO → ❌ NEGADO                                      │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Resposta do /me

### Formato Otimizado para Frontend

```json
{
  "data": {
    "user": {
      "id": 1,
      "name": "Maria Silva",
      "email": "maria@loja.com",
      "avatar_url": "...",
      
      "is_super_admin": false,
      
      "roles": [
        {
          "id": 3,
          "name": "vendedor",
          "display_name": "Vendedor",
          "store_id": null
        }
      ]
    },
    
    "stores": [
      {
        "id": 1,
        "name": "Loja Centro",
        "city": "Tijucas",
        "role": "vendedor"
      },
      {
        "id": 2,
        "name": "Loja Praia",
        "city": "Itapema",
        "role": "gerente"
      }
    ],
    
    "permissions": {
      "global": [
        "payment-methods.view",
        "pedidos.view",
        "pedidos.create",
        "pedidos.update"
      ],
      "by_store": {
        "1": [
          "pedidos.delete"
        ],
        "2": [
          "reports.view",
          "caixa.approve",
          "pedidos.delete"
        ]
      }
    },
    
    "screens": {
      "global": [
        "screen.dashboard",
        "screen.pedidos",
        "screen.pedidos.list",
        "screen.pedidos.create",
        "screen.capas",
        "screen.capas.list",
        "screen.capas.create",
        "screen.caixa",
        "screen.caixa.shift"
      ],
      "by_store": {
        "2": [
          "screen.reports",
          "screen.reports.sales",
          "screen.caixa.approve"
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
        "icon": "chart-bar",
        "route": "/dashboard",
        "screen": "screen.dashboard"
      },
      {
        "id": "pedidos",
        "label": "Pedidos",
        "icon": "shopping-bag",
        "route": "/pedidos",
        "screen": "screen.pedidos",
        "children": [
          {
            "id": "pedidos-list",
            "label": "Lista",
            "route": "/pedidos",
            "screen": "screen.pedidos.list"
          },
          {
            "id": "pedidos-new",
            "label": "Novo Pedido",
            "route": "/pedidos/new",
            "screen": "screen.pedidos.create"
          }
        ]
      },
      {
        "id": "capas",
        "label": "Capas Personalizadas",
        "icon": "photo",
        "route": "/capas",
        "screen": "screen.capas"
      },
      {
        "id": "caixa",
        "label": "Caixa",
        "icon": "cash",
        "route": "/caixa",
        "screen": "screen.caixa"
      }
    ]
  }
}
```

### Uso no Frontend

```typescript
// store/auth.ts
interface AuthState {
  user: User;
  stores: Store[];
  permissions: {
    global: string[];
    by_store: Record<string, string[]>;
  };
  screens: {
    global: string[];
    by_store: Record<string, string[]>;
  };
  menu: MenuItem[];
}

// Verificar permissão
function can(permission: string, storeId?: number): boolean {
  const { permissions } = useAuthStore();
  
  // Verificar global
  if (permissions.global.includes(permission)) {
    return true;
  }
  
  // Verificar por loja
  if (storeId && permissions.by_store[storeId]?.includes(permission)) {
    return true;
  }
  
  return false;
}

// Verificar tela
function canAccessScreen(screen: string, storeId?: number): boolean {
  const { screens } = useAuthStore();
  
  if (screens.global.includes(screen)) {
    return true;
  }
  
  if (storeId && screens.by_store[storeId]?.includes(screen)) {
    return true;
  }
  
  return false;
}

// Uso em componente
<template>
  <SidebarMenu :items="menu" />
  
  <button v-if="can('pedidos.create')">
    Novo Pedido
  </button>
  
  <button v-if="can('pedidos.delete', currentStoreId)">
    Excluir
  </button>
</template>
```

---

## Implementação

### Fase 1: Core (Semana 1)

1. **Migrations**
   - [ ] `permissions` table
   - [ ] `roles` table  
   - [ ] `role_permissions` pivot
   - [ ] `user_roles` table
   - [ ] `user_permissions` (overrides)
   - [ ] `store_permissions` (overrides)

2. **Models**
   - [ ] `Permission` model
   - [ ] `Role` model
   - [ ] `UserPermission` model
   - [ ] `StorePermission` model

3. **Services**
   - [ ] `PermissionResolver` service
   - [ ] Helper methods no `User` model

### Fase 2: Seeders (Semana 1)

1. **Permissions Seeder**
   - [ ] Todas as abilities por módulo
   - [ ] Todas as screens
   - [ ] Todas as features

2. **Roles Seeder**
   - [ ] super_admin (level 100)
   - [ ] admin (level 90)
   - [ ] fabrica (level 80)
   - [ ] gerente (level 70)
   - [ ] conferente (level 60)
   - [ ] estoquista (level 50)
   - [ ] vendedor (level 40)

3. **Role Permissions Seeder**
   - [ ] Atribuir permissions a cada role

### Fase 3: API (Semana 2)

1. **Controllers**
   - [ ] `PermissionController`
   - [ ] `RoleController`
   - [ ] Atualizar `UserController` (roles/permissions)
   - [ ] Atualizar `StoreController` (permissions)

2. **Atualizar `/me`**
   - [ ] Incluir permissions resolvidas
   - [ ] Incluir screens resolvidas
   - [ ] Incluir menu filtrado

### Fase 4: Middleware (Semana 2)

1. **Middleware**
   - [ ] `CheckPermission` middleware
   - [ ] `CheckScreen` middleware

2. **Aplicar nas rotas**
   - [ ] Rotas existentes
   - [ ] Novas rotas

### Fase 5: Migração de Dados

1. **Migrar dados existentes**
   - [ ] `is_super_admin` → role `super_admin`
   - [ ] `StoreUser->role` → `user_roles` com `store_id`
   - [ ] Role `fabrica` (Spatie) → novo sistema

---

## Catálogo de Permissões

### Módulo: Formas de Pagamento

| Permission | Display Name | Tipo |
|------------|--------------|------|
| `payment-methods.view` | Ver formas de pagamento | ability |
| `payment-methods.create` | Criar forma de pagamento | ability |
| `payment-methods.update` | Editar forma de pagamento | ability |
| `payment-methods.delete` | Excluir forma de pagamento | ability |
| `screen.settings.payment-methods` | Menu Formas de Pagamento | screen |

### Módulo: Pedidos

| Permission | Display Name | Tipo |
|------------|--------------|------|
| `pedidos.view` | Ver pedidos | ability |
| `pedidos.view-all` | Ver todos os pedidos (não só os próprios) | ability |
| `pedidos.create` | Criar pedido | ability |
| `pedidos.update` | Editar pedido | ability |
| `pedidos.delete` | Excluir pedido | ability |
| `pedidos.status.update` | Alterar status do pedido | ability |
| `pedidos.bulk-status` | Alterar status em lote | ability |
| `screen.pedidos` | Menu Pedidos | screen |
| `screen.pedidos.list` | Tela Lista de Pedidos | screen |
| `screen.pedidos.create` | Tela Novo Pedido | screen |
| `screen.pedidos.bulk` | Tela Operações em Lote | screen |

### Módulo: Capas Personalizadas

| Permission | Display Name | Tipo |
|------------|--------------|------|
| `capas.view` | Ver capas | ability |
| `capas.view-all` | Ver todas as capas | ability |
| `capas.create` | Criar capa | ability |
| `capas.update` | Editar capa | ability |
| `capas.delete` | Excluir capa | ability |
| `capas.status.update` | Alterar status | ability |
| `capas.payment.update` | Registrar pagamento | ability |
| `capas.send-production` | Enviar para produção | ability |
| `screen.capas` | Menu Capas | screen |
| `screen.capas.list` | Tela Lista | screen |
| `screen.capas.create` | Tela Nova Capa | screen |
| `screen.capas.production` | Tela Enviar Produção | screen |

### Módulo: Caixa

| Permission | Display Name | Tipo |
|------------|--------------|------|
| `caixa.view` | Ver fechamentos | ability |
| `caixa.shift.open` | Abrir turno | ability |
| `caixa.closing.create` | Fazer fechamento | ability |
| `caixa.closing.approve` | Aprovar fechamento | ability |
| `caixa.closing.reject` | Rejeitar fechamento | ability |
| `screen.caixa` | Menu Caixa | screen |
| `screen.caixa.shift` | Tela Meu Turno | screen |
| `screen.caixa.closing` | Tela Fechamento | screen |
| `screen.caixa.approve` | Tela Aprovar | screen |

### Módulo: Relatórios

| Permission | Display Name | Tipo |
|------------|--------------|------|
| `reports.view` | Ver relatórios | ability |
| `reports.sales` | Relatório de vendas | ability |
| `reports.ranking` | Relatório de ranking | ability |
| `reports.performance` | Relatório de performance | ability |
| `reports.export` | Exportar relatórios | ability |
| `screen.reports` | Menu Relatórios | screen |
| `screen.reports.sales` | Tela Vendas | screen |
| `screen.reports.ranking` | Tela Ranking | screen |
| `screen.reports.performance` | Tela Performance | screen |

### Módulo: Produção

| Permission | Display Name | Tipo |
|------------|--------------|------|
| `producao.view` | Ver produção | ability |
| `producao.cart.view` | Ver carrinho | ability |
| `producao.cart.add` | Adicionar ao carrinho | ability |
| `producao.cart.remove` | Remover do carrinho | ability |
| `producao.cart.close` | Fechar carrinho | ability |
| `producao.orders.receive` | Receber pedido | ability |
| `producao.orders.cancel` | Cancelar pedido | ability |
| `screen.producao` | Menu Produção | screen |
| `screen.producao.cart` | Tela Carrinho | screen |
| `screen.producao.orders` | Tela Pedidos | screen |

### Módulo: Fábrica

| Permission | Display Name | Tipo |
|------------|--------------|------|
| `fabrica.view` | Ver portal fábrica | ability |
| `fabrica.orders.accept` | Aceitar pedido | ability |
| `fabrica.orders.dispatch` | Despachar pedido | ability |
| `fabrica.orders.download` | Baixar fotos | ability |
| `screen.fabrica` | Menu Fábrica | screen |
| `screen.fabrica.orders` | Tela Pedidos | screen |
| `screen.fabrica.dispatch` | Tela Despacho | screen |

### Módulo: Administração

| Permission | Display Name | Tipo |
|------------|--------------|------|
| `admin.users.view` | Ver usuários | ability |
| `admin.users.create` | Criar usuário | ability |
| `admin.users.update` | Editar usuário | ability |
| `admin.users.delete` | Excluir usuário | ability |
| `admin.stores.view` | Ver lojas | ability |
| `admin.stores.create` | Criar loja | ability |
| `admin.stores.update` | Editar loja | ability |
| `admin.stores.delete` | Excluir loja | ability |
| `admin.roles.view` | Ver roles | ability |
| `admin.roles.manage` | Gerenciar roles | ability |
| `admin.permissions.view` | Ver permissões | ability |
| `admin.permissions.manage` | Gerenciar permissões | ability |
| `admin.whatsapp.manage` | Gerenciar WhatsApp | ability |
| `admin.audit.view` | Ver logs de auditoria | ability |
| `screen.admin` | Menu Administração | screen |
| `screen.admin.users` | Tela Usuários | screen |
| `screen.admin.stores` | Tela Lojas | screen |
| `screen.admin.roles` | Tela Roles | screen |
| `screen.admin.permissions` | Tela Permissões | screen |
| `screen.admin.whatsapp` | Tela WhatsApp | screen |
| `screen.admin.logs` | Tela Logs | screen |

### Features Especiais

| Permission | Display Name | Tipo |
|------------|--------------|------|
| `feature.whatsapp-notifications` | Enviar notificações WhatsApp | feature |
| `feature.bulk-operations` | Operações em lote | feature |
| `feature.export-excel` | Exportar para Excel | feature |
| `feature.advanced-filters` | Filtros avançados | feature |

---

## Próximos Passos

Se aprovado, posso começar a implementação pela **Fase 1**:

1. Criar as migrations
2. Criar os models
3. Criar o `PermissionResolver` service
4. Criar o seeder com todas as permissions e roles

Qual fase você quer que eu comece primeiro?

---

*Aguardo seu feedback! 🚀*
