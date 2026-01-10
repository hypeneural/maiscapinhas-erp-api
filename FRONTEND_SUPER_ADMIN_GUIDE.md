# Super Administrador - Guia de Implementação Frontend

Este documento explica as alterações na API para suporte ao perfil **Super Administrador** e como o frontend deve implementar as telas.

---

## Sumário

1. [O que é o Super Admin?](#o-que-é-o-super-admin)
2. [Identificando um Super Admin](#identificando-um-super-admin)
3. [TypeScript Schemas](#typescript-schemas)
4. [Endpoints CRUD de Usuários](#endpoints-crud-de-usuários)
5. [Controle de Acesso (RBAC)](#controle-de-acesso-rbac)
6. [Sugestões de Telas](#sugestões-de-telas)

---

## O que é o Super Admin?

O **Super Administrador** é um perfil especial que:

| Característica | Descrição |
|----------------|-----------|
| 🏪 **Acesso a Lojas** | Acessa TODAS as lojas, mesmo sem vínculo na tabela `store_users` |
| 🔓 **Endpoints** | Acessa TODOS os endpoints protegidos sem restrição |
| 👥 **CRUD Usuários** | Pode criar, editar e excluir qualquer usuário |
| ⚙️ **Configurações** | Pode criar regras globais de bônus/comissão |
| 🔑 **Criar Super Admins** | Pode promover outros usuários a Super Admin |

> [!IMPORTANT]
> O Super Admin **NÃO precisa de vínculos `store_users`** para funcionar. Ele tem acesso implícito a tudo.

---

## Identificando um Super Admin

### Endpoint `/me`

Ao fazer login, verifique o campo `is_super_admin` na resposta do `/me`:

```http
GET /api/v1/me
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "user": {
      "id": 11,
      "name": "Anderson",
      "email": "anderson@maiscapinhas.com.br",
      "active": true,
      "is_super_admin": true,  // 👈 NOVO CAMPO
      "whatsapp": null,
      "avatar_url": null,
      "instagram": null,
      "birth_date": null,
      "hire_date": null,
      "created_at": "2026-01-09T23:15:20+00:00"
    },
    "stores": []  // ⚠️ Pode estar vazio para super admin!
  }
}
```

### Lógica no AuthContext

```typescript
// authContext.tsx
interface User {
  id: number;
  name: string;
  email: string;
  active: boolean;
  is_super_admin: boolean;  // 👈 Adicionar
  whatsapp?: string;
  avatar_url?: string;
  instagram?: string;
  birth_date?: string;
  hire_date?: string;
  created_at: string;
}

// Verificar se é super admin
const isSuperAdmin = user?.is_super_admin === true;
```

---

## TypeScript Schemas

### User Schema Atualizado

```typescript
// types/user.ts

export interface User {
  id: number;
  name: string;
  email: string;
  active: boolean;
  is_super_admin: boolean;  // 👈 NOVO
  
  // Profile
  birth_date?: string | null;  // 'YYYY-MM-DD'
  hire_date?: string | null;   // 'YYYY-MM-DD'
  whatsapp?: string | null;
  avatar_url?: string | null;
  instagram?: string | null;
  cpf?: string | null;         // Apenas visível para admins
  pix_key?: string | null;     // Apenas visível para admins
  
  // Timestamps
  created_at: string;
  updated_at?: string;
  
  // Relacionamentos (quando carregados)
  stores?: UserStore[];
}

export interface UserStore {
  store_id: number;
  store_name: string;
  role: 'admin' | 'gerente' | 'conferente' | 'vendedor';
}
```

### Request Schemas

```typescript
// types/requests.ts

// Criar usuário
export interface CreateUserRequest {
  name: string;
  email: string;
  password: string;
  active?: boolean;            // default: true
  is_super_admin?: boolean;    // 👈 NOVO - default: false
  stores?: StoreAssignment[];
}

export interface StoreAssignment {
  store_id: number;
  role: 'admin' | 'gerente' | 'conferente' | 'vendedor';
}

// Atualizar usuário
export interface UpdateUserRequest {
  name?: string;
  email?: string;
  password?: string;
  active?: boolean;
  is_super_admin?: boolean;    // 👈 NOVO
}

// Vincular usuário a loja
export interface BindUserToStoreRequest {
  user_id: number;
  role: 'admin' | 'gerente' | 'conferente' | 'vendedor';
}
```

---

## Endpoints CRUD de Usuários

### Listar Usuários
```http
GET /api/v1/admin/users
Authorization: Bearer {token}
```

**Query Params:**
| Param | Tipo | Descrição |
|-------|------|-----------|
| `search` | string | Busca por nome ou email |
| `active` | boolean | Filtrar por status |
| `store_id` | number | Filtrar por loja |
| `per_page` | number | Itens por página (1-100) |

**Response:**
```json
{
  "data": [
    {
      "id": 11,
      "name": "Super Admin",
      "email": "anderson@maiscapinhas.com.br",
      "active": true,
      "is_super_admin": true,
      "stores": []
    },
    {
      "id": 1,
      "name": "Admin Normal",
      "email": "admin@test.com",
      "active": true,
      "is_super_admin": false,
      "stores": [
        { "store_id": 1, "store_name": "Loja Tijucas", "role": "admin" }
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 2,
    "last_page": 1
  }
}
```

---

### Criar Usuário
```http
POST /api/v1/admin/users
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "name": "Novo Usuário",
  "email": "novo@maiscapinhas.com.br",
  "password": "senha123456",
  "active": true,
  "is_super_admin": false,
  "stores": [
    { "store_id": 1, "role": "vendedor" }
  ]
}
```

**Response:** `201 Created`
```json
{
  "data": {
    "id": 12,
    "name": "Novo Usuário",
    "email": "novo@maiscapinhas.com.br",
    "active": true,
    "is_super_admin": false,
    "stores": [
      { "store_id": 1, "store_name": "Loja Tijucas", "role": "vendedor" }
    ]
  }
}
```

---

### Atualizar Usuário
```http
PUT /api/v1/admin/users/{id}
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body (parcial):**
```json
{
  "is_super_admin": true
}
```

**Response:** `200 OK`
```json
{
  "data": {
    "id": 12,
    "name": "Novo Usuário",
    "email": "novo@maiscapinhas.com.br",
    "active": true,
    "is_super_admin": true,
    "stores": [...]
  }
}
```

---

### Desativar Usuário
```http
DELETE /api/v1/admin/users/{id}
Authorization: Bearer {token}
```

**Response:** `200 OK`
```json
{
  "data": { "message": "Usuário desativado com sucesso." }
}
```

> [!WARNING]
> Não é exclusão física - apenas define `active = false` e revoga tokens.

---

## Controle de Acesso (RBAC)

### Hook Sugerido: `usePermissions`

```typescript
// hooks/usePermissions.ts
import { useAuth } from '@/contexts/AuthContext';

export function usePermissions() {
  const { user, stores, currentStoreId } = useAuth();
  
  // Super admin tem tudo
  const isSuperAdmin = user?.is_super_admin === true;
  
  // Role na loja atual
  const currentStore = stores?.find(s => s.id === currentStoreId);
  const currentRole = currentStore?.role;
  
  // Helper functions
  const hasRole = (roles: string[]) => {
    if (isSuperAdmin) return true;
    return currentRole && roles.includes(currentRole);
  };
  
  const isAdmin = hasRole(['admin']);
  const isManager = hasRole(['admin', 'gerente']);
  const isApprover = hasRole(['admin', 'gerente', 'conferente']);
  
  // Super admin pode acessar qualquer loja
  const canAccessStore = (storeId: number) => {
    if (isSuperAdmin) return true;
    return stores?.some(s => s.id === storeId) ?? false;
  };
  
  // Super admin pode gerenciar outros super admins
  const canManageSuperAdmins = isSuperAdmin;
  
  return {
    isSuperAdmin,
    isAdmin,
    isManager,
    isApprover,
    currentRole,
    hasRole,
    canAccessStore,
    canManageSuperAdmins,
  };
}
```

### Uso em Componentes

```tsx
function UserManagement() {
  const { isSuperAdmin, canManageSuperAdmins } = usePermissions();
  
  return (
    <div>
      {/* Toggle Super Admin - só aparece para super admins */}
      {canManageSuperAdmins && (
        <FormField
          label="Super Administrador"
          description="Dá acesso total ao sistema"
        >
          <Switch
            checked={user.is_super_admin}
            onChange={(checked) => handleToggleSuperAdmin(checked)}
          />
        </FormField>
      )}
    </div>
  );
}
```

---

## Sugestões de Telas

### 1. Tela de Login

**Sem alterações necessárias** - o super admin faz login normalmente.

---

### 2. Seletor de Loja (Header/Sidebar)

```tsx
function StoreSelector() {
  const { user, stores } = useAuth();
  const { isSuperAdmin } = usePermissions();
  
  // Super admin: mostrar TODAS as lojas do sistema
  // Usuário normal: mostrar apenas suas lojas
  const availableStores = isSuperAdmin 
    ? await fetchAllStores()  // GET /api/v1/stores/all
    : stores;
  
  return (
    <Select>
      {isSuperAdmin && (
        <SelectItem value="all">
          🌐 Todas as Lojas (Super Admin)
        </SelectItem>
      )}
      {availableStores.map(store => (
        <SelectItem key={store.id} value={store.id}>
          {store.name}
        </SelectItem>
      ))}
    </Select>
  );
}
```

---

### 3. Tela de Gestão de Usuários

#### Lista de Usuários

```
┌─────────────────────────────────────────────────────────────────┐
│ Usuários                                          [+ Novo]      │
├─────────────────────────────────────────────────────────────────┤
│ 🔍 Buscar...     [Loja ▼]  [Status ▼]                          │
├─────────────────────────────────────────────────────────────────┤
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ 👑 Anderson                           Super Admin           │ │
│ │    anderson@maiscapinhas.com.br       ● Ativo               │ │
│ │    Acesso: TODAS AS LOJAS                       [Editar]    │ │
│ └─────────────────────────────────────────────────────────────┘ │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ 👤 Carlos Gerente                                           │ │
│ │    carlos@test.com                    ● Ativo               │ │
│ │    Tijucas (gerente), Itapema (admin)           [Editar]    │ │
│ └─────────────────────────────────────────────────────────────┘ │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ 👤 João Vendedor                                            │ │
│ │    joao@test.com                      ○ Inativo             │ │
│ │    Tijucas (vendedor)                           [Editar]    │ │
│ └─────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
```

**Indicadores visuais:**
- 👑 Ícone de coroa para Super Admin
- Badge "Super Admin" destacado
- Texto "TODAS AS LOJAS" para super admin

---

#### Formulário de Edição/Criação

```
┌─────────────────────────────────────────────────────────────────┐
│ Editar Usuário                                                  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ ┌─ Dados Básicos ─────────────────────────────────────────────┐ │
│ │ Nome:     [Anderson                               ]         │ │
│ │ Email:    [anderson@maiscapinhas.com.br           ]         │ │
│ │ Senha:    [••••••••] (deixe vazio para manter)              │ │
│ │ Status:   [● Ativo  ○ Inativo]                              │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ ┌─ Permissões Especiais ──────────────────────────────────────┐ │
│ │                                                             │ │
│ │ ⚠️ SUPER ADMINISTRADOR                                      │ │
│ │ ┌─────────────────────────────────────────────────────────┐ │ │
│ │ │ [✓] Conceder acesso de Super Administrador              │ │ │
│ │ │                                                         │ │ │
│ │ │ ⚡ Ao ativar, este usuário terá:                        │ │ │
│ │ │ • Acesso a TODAS as lojas                               │ │ │
│ │ │ • Acesso a TODOS os endpoints                           │ │ │
│ │ │ • Poder de criar outros super admins                    │ │ │
│ │ └─────────────────────────────────────────────────────────┘ │ │
│ │                                                             │ │
│ │ 🔴 Esta opção só é visível para Super Administradores      │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ ┌─ Vínculos com Lojas ────────────────────────────────────────┐ │
│ │ (Ignorado para Super Admins - eles têm acesso a tudo)       │ │
│ │                                                             │ │
│ │ Loja Tijucas          [admin     ▼]  [🗑️]                   │ │
│ │ Loja Itapema          [gerente   ▼]  [🗑️]                   │ │
│ │                                                             │ │
│ │ [+ Adicionar loja]                                          │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│                              [Cancelar]  [💾 Salvar]            │
└─────────────────────────────────────────────────────────────────┘
```

---

### 4. Dashboard (Adaptações)

```tsx
function Dashboard() {
  const { isSuperAdmin } = usePermissions();
  
  if (isSuperAdmin) {
    return (
      <div>
        {/* Super admin vê visão consolidada */}
        <Alert variant="info">
          👑 Você está logado como Super Administrador. 
          Visualizando dados de todas as lojas.
        </Alert>
        
        <ConsolidatedDashboard />
      </div>
    );
  }
  
  return <StoreDashboard />;
}
```

---

### 5. Menu/Sidebar (Adaptações)

```tsx
function Sidebar() {
  const { isSuperAdmin, isAdmin } = usePermissions();
  
  return (
    <nav>
      {/* Itens comuns */}
      <NavItem href="/dashboard">Dashboard</NavItem>
      <NavItem href="/vendas">Vendas</NavItem>
      
      {/* Admin ou Super Admin */}
      {(isAdmin || isSuperAdmin) && (
        <>
          <NavItem href="/configuracoes/usuarios">Usuários</NavItem>
          <NavItem href="/configuracoes/lojas">Lojas</NavItem>
        </>
      )}
      
      {/* Apenas Super Admin */}
      {isSuperAdmin && (
        <NavItem href="/configuracoes/super-admins">
          👑 Super Admins
        </NavItem>
      )}
    </nav>
  );
}
```

---

## Resumo de Alterações no Frontend

| Área | Alteração Necessária |
|------|---------------------|
| **AuthContext** | Adicionar `is_super_admin` ao tipo User |
| **usePermissions hook** | Criar/atualizar para suportar super admin |
| **Seletor de Loja** | Mostrar todas as lojas para super admin |
| **Lista de Usuários** | Destacar super admins com ícone/badge |
| **Form de Usuário** | Adicionar toggle "Super Admin" (condicional) |
| **Dashboard** | Mostrar visão consolidada para super admin |
| **Sidebar/Menu** | Adicionar itens exclusivos para super admin |
| **Guards de Rota** | Atualizar lógica para incluir super admin |

---

## Checklist de Implementação

- [ ] Atualizar interface `User` com campo `is_super_admin`
- [ ] Criar/atualizar hook `usePermissions`
- [ ] Atualizar `AuthContext` para expor `isSuperAdmin`
- [ ] Adaptar seletor de lojas no header
- [ ] Adicionar indicador visual de Super Admin na lista de usuários
- [ ] Adicionar toggle Super Admin no form de edição
- [ ] Condicionar toggle apenas para Super Admins
- [ ] Atualizar guards de rota (`RoleGuard`, etc.)
- [ ] Adicionar banner/indicador de modo Super Admin no dashboard
