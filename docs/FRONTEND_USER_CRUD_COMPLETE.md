# 📚 Documentação Completa - CRUD Usuários, Roles, Permissões e Lojas

> **Versão:** 3.0  
> **Data:** 16/01/2026  
> **Equipe:** Backend → Frontend  
> **Status:** ✅ ATUALIZADO E VALIDADO

---

## 📋 Índice

1. [Visão Geral do Sistema](#1-visão-geral-do-sistema)
2. [Modelo de Dados](#2-modelo-de-dados)
3. [CRUD de Usuários](#3-crud-de-usuários)
4. [Gestão de Lojas](#4-gestão-de-lojas)
5. [Vínculo Usuário ↔ Loja](#5-vínculo-usuário--loja)
6. [Sistema de Roles](#6-sistema-de-roles)
7. [Sistema de Permissões](#7-sistema-de-permissões)
8. [Permission Overrides](#8-permission-overrides)
9. [Hierarquia e Níveis](#9-hierarquia-e-níveis)
10. [Audit Logs](#10-audit-logs)
11. [Tipos TypeScript](#11-tipos-typescript)
12. [React Query Hooks](#12-react-query-hooks)

---

## 1. Visão Geral do Sistema

### Arquitetura de Permissões

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              USUÁRIO                                         │
├─────────────────────────────────────────────────────────────────────────────┤
│  is_super_admin: boolean      → Acesso TOTAL ao sistema                     │
│  roles: Role[]                → Via tabela user_store_roles                 │
│  stores: StoreBinding[]       → Via tabela store_users                      │
│  permission_overrides: []     → Permissões individuais (grant/deny)         │
└─────────────────────────────────────────────────────────────────────────────┘

                           Permissão Final = 
           Role Permissions + Store Overrides + User Overrides
```

### Duas Tabelas de Relacionamento

| Tabela | Propósito | Campos Principais |
|--------|-----------|-------------------|
| `store_users` | Vínculo direto usuário-loja com role simples | `user_id`, `store_id`, `role` (string) |
| `user_store_roles` | Atribuição de roles (global ou por loja) | `user_id`, `role_id`, `store_id` (nullable) |

> **Nota:** O sistema suporta ambas as tabelas. `store_users` é usada para vínculos de loja simples, enquanto `user_store_roles` permite roles globais (quando `store_id` é null).

---

## 2. Modelo de Dados

### Campos do Usuário

```json
{
  "id": 1,
  "name": "João Silva",
  "email": "joao@empresa.com",
  "active": true,
  "is_super_admin": false,
  
  // Campos de Perfil
  "whatsapp": "47999999999",
  "birth_date": "1990-05-15",
  "hire_date": "2024-01-10",
  "avatar_url": "https://...",
  "instagram": "@joao",
  "cpf": "123.456.789-00",
  "pix_key": "joao@pix.com",
  
  // Campos de Endereço
  "zip_code": "88160-000",
  "street": "Rua das Flores",
  "number": "123",
  "complement": "Sala 1",
  "neighborhood": "Centro",
  "city": "Biguaçu",
  "state": "SC",
  
  // Relacionamentos
  "stores": [
    { "id": 1, "name": "Loja Centro", "city": "Tijucas", "role": "vendedor" }
  ],
  
  // Timestamps
  "created_at": "2026-01-01T00:00:00Z",
  "updated_at": "2026-01-16T00:00:00Z"
}
```

### Campos Obrigatórios na Criação

| Campo | Tipo | Validação |
|-------|------|-----------|
| `name` | string | Obrigatório, min: 1 |
| `email` | string | Obrigatório, único, formato email |
| `password` | string | Obrigatório, min: 8 caracteres |

### Campos Opcionais

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `active` | boolean | Default: true |
| `is_super_admin` | boolean | Default: false |
| `whatsapp` | string | Formato: 11999999999 |
| `birth_date` | date | Formato: YYYY-MM-DD |
| `hire_date` | date | Data de contratação |
| `stores` | array | Vínculos com lojas |

---

## 3. CRUD de Usuários

### Endpoints Base

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/api/v1/admin/users` | Listar usuários |
| POST | `/api/v1/admin/users` | Criar usuário |
| GET | `/api/v1/admin/users/{id}` | Detalhes do usuário |
| PUT/PATCH | `/api/v1/admin/users/{id}` | Atualizar usuário |
| DELETE | `/api/v1/admin/users/{id}` | Desativar usuário (soft) |

### Filtros Disponíveis

```http
GET /api/v1/admin/users?search=joao&active=true&store_id=1&per_page=25
```

| Filtro | Tipo | Descrição |
|--------|------|-----------|
| `search` | string | Busca por nome ou email |
| `active` | boolean | Filtrar por status ativo |
| `store_id` | number | Filtrar por loja específica |
| `has_stores` | boolean | Com ou sem lojas vinculadas |
| `role` | string | Filtrar por role global |
| `is_global_admin` | boolean | Super admin ou admin em loja |
| `per_page` | number | Itens por página (1-100, default: 25) |
| `page` | number | Página atual |

### Criar Usuário

```http
POST /api/v1/admin/users
Content-Type: application/json

{
  "name": "João Silva",
  "email": "joao@empresa.com",
  "password": "senha12345",
  "active": true,
  "whatsapp": "47999999999",
  "birth_date": "1990-05-15",
  "stores": [
    { "store_id": 1, "role": "vendedor" },
    { "store_id": 2, "role": "vendedor" }
  ]
}
```

**Response 201:**
```json
{
  "data": {
    "id": 43,
    "name": "João Silva",
    "email": "joao@empresa.com",
    "active": true,
    "is_super_admin": false,
    "is_global_admin": false,
    "stores": [
      { "id": 1, "name": "Loja Centro", "city": "Tijucas", "role": "vendedor" }
    ],
    "created_at": "2026-01-16T11:00:00Z"
  }
}
```

### Desativar Usuário

```http
DELETE /api/v1/admin/users/{id}
```

> ⚠️ **Nota:** Não exclui o usuário do banco. Define `active = false` e revoga todos os tokens de acesso.

---

## 4. Gestão de Lojas

### Endpoints Base

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/api/v1/admin/stores` | Listar lojas |
| POST | `/api/v1/admin/stores` | Criar loja |
| GET | `/api/v1/admin/stores/{id}` | Detalhes da loja |
| PUT | `/api/v1/admin/stores/{id}` | Atualizar loja |
| DELETE | `/api/v1/admin/stores/{id}` | Desativar loja |
| POST | `/api/v1/admin/stores/validate-hours` | Validar horários |

### Campos da Loja

```json
{
  "id": 1,
  "name": "Mais Capinhas Centro",
  "codigo": "MC001",
  "city": "Tijucas",
  "state": "SC",
  "address": "Rua Principal, 100",
  "neighborhood": "Centro",
  "zip_code": "88200-000",
  "phone": "4733334444",
  "whatsapp": "47999998888",
  "instagram": "@maiscapinhas",
  "cnpj": "12.345.678/0001-99",
  "active": true,
  "bio_enabled": true,
  "latitude": "-27.2345678",
  "longitude": "-48.7654321",
  "troco_padrao": 100.00,
  "opening_hours": {
    "weekly": {
      "monday": { "open": "09:00", "close": "18:00" },
      "saturday": { "open": "09:00", "close": "13:00" }
    }
  },
  "photo_url": "https://..."
}
```

---

## 5. Vínculo Usuário ↔ Loja

### Endpoints

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| PUT | `/api/v1/admin/users/{id}/stores` | Sync (replace all) |
| POST | `/api/v1/admin/users/{id}/stores/bulk` | Adicionar lojas |
| PATCH | `/api/v1/admin/users/{id}/stores/bulk` | Atualizar role |
| DELETE | `/api/v1/admin/users/{id}/stores/bulk` | Remover lojas |

### Endpoints via Loja

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/api/v1/admin/stores/{id}/users` | Listar usuários da loja |
| POST | `/api/v1/admin/stores/{id}/users` | Vincular usuário |
| PUT | `/api/v1/admin/stores/{id}/users/{user}` | Atualizar role |
| DELETE | `/api/v1/admin/stores/{id}/users/{user}` | Desvincular |

### Bulk Add (Adicionar a várias lojas)

```http
POST /api/v1/admin/users/{id}/stores/bulk
Content-Type: application/json

{
  "stores": [
    { "store_id": 1, "role": "vendedor" },
    { "store_id": 2, "role": "vendedor" },
    { "store_id": 3, "role": "gerente" }
  ]
}
```

**Response:**
```json
{
  "data": {
    "message": "3 vínculo(s) criado(s), 0 ignorado(s).",
    "created": [1, 2, 3],
    "skipped": []
  }
}
```

### Sync Stores (Replace All)

```http
PUT /api/v1/admin/users/{id}/stores
Content-Type: application/json

{
  "stores": [
    { "store_id": 1, "role": "admin" }
  ]
}
```

> ⚠️ **ATENÇÃO:** Este endpoint REMOVE todos os vínculos existentes e cria apenas os listados!

### Bulk Update Role

```http
PATCH /api/v1/admin/users/{id}/stores/bulk
Content-Type: application/json

{
  "role": "gerente",
  "store_ids": [1, 2, 3]
}
```

### Bulk Remove

```http
DELETE /api/v1/admin/users/{id}/stores/bulk
Content-Type: application/json

{
  "store_ids": [2, 3]
}
```

### Vincular via Loja

```http
POST /api/v1/admin/stores/{id}/users
Content-Type: application/json

{
  "user_id": 5,
  "role": "vendedor"
}
```

---

## 6. Sistema de Roles

### Hierarquia de Roles

| Role | Name | Level | Descrição |
|------|------|-------|-----------|
| Super Admin | `super_admin` | 100 | Acesso total ao sistema |
| Admin | `admin` | 90 | Administrador geral |
| Fábrica | `fabrica` | 80 | Portal da fábrica |
| Gerente | `gerente` | 70 | Gestão de loja |
| Conferente | `conferente` | 60 | Conferência de caixa |
| Estoquista | `estoquista` | 50 | Gestão de estoque |
| Vendedor | `vendedor` | 40 | Vendas na loja |

### Endpoints de Roles

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/api/v1/admin/roles` | Listar todas as roles |
| GET | `/api/v1/admin/roles/{id}` | Detalhes com permissões |
| POST | `/api/v1/admin/roles` | Criar role |
| PUT | `/api/v1/admin/roles/{id}` | Atualizar role |
| DELETE | `/api/v1/admin/roles/{id}` | Excluir role |
| POST | `/api/v1/admin/roles/{id}/clone` | Clonar role |
| POST | `/api/v1/admin/roles/{id}/permissions` | Sync permissões |
| PUT | `/api/v1/admin/roles/{id}/permissions` | Add/remove permissões |
| GET | `/api/v1/admin/roles/available` | Roles disponíveis |

### Listar Roles Disponíveis

```http
GET /api/v1/admin/roles/available
```

**Response:**
```json
{
  "data": [
    { "id": 1, "name": "super_admin", "display_name": "Super Admin", "level": 100, "is_system": true },
    { "id": 2, "name": "admin", "display_name": "Administrador", "level": 90, "is_system": true },
    { "id": 3, "name": "fabrica", "display_name": "Fábrica", "level": 80, "is_system": true },
    { "id": 4, "name": "gerente", "display_name": "Gerente", "level": 70, "is_system": true },
    { "id": 5, "name": "conferente", "display_name": "Conferente", "level": 60, "is_system": true },
    { "id": 6, "name": "estoquista", "display_name": "Estoquista", "level": 50, "is_system": true },
    { "id": 7, "name": "vendedor", "display_name": "Vendedor", "level": 40, "is_system": true }
  ]
}
```

### Atribuição de Roles (user_store_roles)

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/api/v1/admin/users/{id}/roles` | Listar roles do usuário |
| POST | `/api/v1/admin/users/{id}/roles` | Atribuir role |
| PUT | `/api/v1/admin/users/{id}/roles/sync` | Sync roles (replace all) |
| DELETE | `/api/v1/admin/users/{id}/roles/{assignment}` | Remover role |

### Atribuir Role

```http
POST /api/v1/admin/users/{id}/roles
Content-Type: application/json

{
  "role_id": 3,
  "store_id": null    // null = global, ou ID da loja
}
```

**Response:**
```json
{
  "message": "Role 'Fábrica' atribuído globalmente.",
  "data": {
    "id": 15,
    "role": { "id": 3, "name": "fabrica", "display_name": "Fábrica", "level": 80 },
    "store": null,
    "is_global": true,
    "created_at": "2026-01-16T11:00:00Z"
  }
}
```

### Sync Roles

```http
PUT /api/v1/admin/users/{id}/roles/sync
Content-Type: application/json

{
  "assignments": [
    { "role_id": 3, "store_id": null },
    { "role_id": 2, "store_id": 1 }
  ]
}
```

### Clonar Role

```http
POST /api/v1/admin/roles/{id}/clone
Content-Type: application/json

{
  "name": "conferente-senior",
  "display_name": "Conferente Sênior",
  "description": "Conferente com acesso a relatórios"
}
```

### Editar Permissões da Role

```http
PUT /api/v1/admin/roles/{id}/permissions
Content-Type: application/json

{
  "add": ["reports.view", "exports.excel"],
  "remove": ["pedidos.delete"]
}
```

---

## 7. Sistema de Permissões

### Endpoints

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/api/v1/admin/permissions` | Listar permissões |
| GET | `/api/v1/admin/permissions/grouped` | Agrupadas por módulo |
| GET | `/api/v1/admin/permissions/by-type` | Agrupadas por tipo |
| GET | `/api/v1/admin/permissions/modules` | Lista de módulos |
| GET | `/api/v1/admin/permissions/conventions` | Convenções de nomes |
| GET | `/api/v1/admin/permissions/most-granted` | Mais concedidas |
| POST | `/api/v1/admin/permissions` | Criar permissão |
| POST | `/api/v1/admin/permissions/bulk` | Criar em lote |
| POST | `/api/v1/admin/permissions/preview` | Preview de mudanças |
| POST | `/api/v1/admin/permissions/bulk-grant` | Conceder em lote |
| GET | `/api/v1/admin/permissions/{id}` | Detalhes |
| PUT | `/api/v1/admin/permissions/{id}` | Atualizar |
| DELETE | `/api/v1/admin/permissions/{id}` | Excluir |
| GET | `/api/v1/admin/permissions/{name}/users` | Usuários com permissão |

### Permissões Agrupadas

```http
GET /api/v1/admin/permissions/grouped
```

**Response:**
```json
{
  "data": [
    {
      "module": "pedidos",
      "module_display": "Pedidos",
      "count": 12,
      "permissions": [
        { "id": 1, "name": "pedidos.view", "display_name": "Ver pedidos", "type": "ability" },
        { "id": 2, "name": "pedidos.create", "display_name": "Criar pedidos", "type": "ability" }
      ]
    }
  ]
}
```

### Tipos de Permissão

| Tipo | Descrição | Exemplo |
|------|-----------|---------|
| `ability` | Ação específica | `pedidos.create`, `pedidos.delete` |
| `screen` | Acesso a tela | `screen.pedidos`, `screen.dashboard` |
| `feature` | Funcionalidade | `feature.export-excel` |

### Preview de Mudanças

```http
POST /api/v1/admin/permissions/preview
Content-Type: application/json

{
  "user_id": 1,
  "add_permissions": ["reports.view"],
  "remove_permissions": ["pedidos.delete"]
}
```

**Response:**
```json
{
  "user_id": 1,
  "user_name": "João Vendedor",
  "current": ["pedidos.view", "pedidos.delete"],
  "after": ["pedidos.view", "reports.view"],
  "added": ["reports.view"],
  "removed": ["pedidos.delete"],
  "total_change": 2
}
```

### Copiar Permissões entre Usuários

```http
POST /api/v1/admin/users/{targetId}/permissions/copy-from/{sourceId}
Content-Type: application/json

{
  "include_temporary": false,
  "expires_at": "2026-02-01"
}
```

### Bulk Grant

```http
POST /api/v1/admin/permissions/bulk-grant
Content-Type: application/json

{
  "user_ids": [1, 2, 3],
  "permissions": ["reports.view", "exports.excel"],
  "expires_at": "2026-02-01T23:59:59Z",
  "reason": "Projeto especial Q1"
}
```

---

## 8. Permission Overrides

### User Permission Overrides

Permite conceder ou negar permissões específicas a um usuário, sobrescrevendo as permissões da role.

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/api/v1/admin/users/{id}/permission-overrides` | Listar overrides |
| POST | `/api/v1/admin/users/{id}/permission-overrides` | Criar override |
| POST | `/api/v1/admin/users/{id}/permission-overrides/bulk` | Criar em lote |
| GET | `/api/v1/admin/users/{id}/permission-overrides/effective` | Permissões efetivas |
| DELETE | `/api/v1/admin/users/{id}/permission-overrides/clear` | Limpar todos |
| PUT | `/api/v1/admin/users/{id}/permission-overrides/{id}` | Atualizar |
| DELETE | `/api/v1/admin/users/{id}/permission-overrides/{id}` | Remover |

### Criar Override

```http
POST /api/v1/admin/users/{id}/permission-overrides
Content-Type: application/json

{
  "permission_id": 15,
  "type": "grant",                     // "grant" ou "deny"
  "store_id": null,                    // null = global
  "expires_at": "2026-02-01T00:00:00Z",
  "reason": "Cobertura de férias"
}
```

### Permissões Efetivas

```http
GET /api/v1/admin/users/{id}/permission-overrides/effective
```

**Response:**
```json
{
  "user_id": 5,
  "permissions": [
    {
      "permission": "pedidos.view",
      "has_access": true,
      "source": "role",
      "role_name": "vendedor"
    },
    {
      "permission": "pedidos.delete",
      "has_access": true,
      "source": "user_override",
      "expires_at": "2026-02-01"
    }
  ]
}
```

### Store Permission Overrides

Permite conceder ou negar permissões para TODOS os usuários de uma loja.

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/api/v1/admin/stores/{id}/permission-overrides` | Listar |
| POST | `/api/v1/admin/stores/{id}/permission-overrides` | Criar |
| POST | `/api/v1/admin/stores/{id}/permission-overrides/bulk` | Criar em lote |
| DELETE | `/api/v1/admin/stores/{id}/permission-overrides/clear` | Limpar todos |
| PUT | `/api/v1/admin/stores/{id}/permission-overrides/{id}` | Atualizar |
| DELETE | `/api/v1/admin/stores/{id}/permission-overrides/{id}` | Remover |

### Criar Store Override

```http
POST /api/v1/admin/stores/{id}/permission-overrides
Content-Type: application/json

{
  "permission_id": 15,
  "granted": true     // true = concede, false = nega
}
```

### Modelo de Prioridade

```
Permissão Final = 
  1. User Override (maior prioridade)
  2. Store Override
  3. Role Permissions (menor prioridade)
```

| Tipo | Efeito |
|------|--------|
| `grant` | Concede permissão mesmo sem role |
| `deny` | Nega permissão mesmo com role |

---

## 9. Hierarquia e Níveis

### Regra de Atribuição de Roles

> Um usuário só pode atribuir roles com `level` **menor** que o seu.

| Usuário | Level | Pode atribuir |
|---------|-------|---------------|
| Super Admin | 100 | Todas as roles |
| Admin | 90 | fabrica, gerente, conferente, estoquista, vendedor |
| Gerente | 70 | conferente, estoquista, vendedor |
| Conferente | 60 | estoquista, vendedor |

### Quem pode fazer o quê

| Ação | Super Admin | Admin | Gerente |
|------|-------------|-------|---------|
| Criar usuário | ✅ | ✅* | ❌ |
| Editar usuário | ✅ | ✅* | ❌ |
| Vincular loja | ✅ | ✅* | ❌ |
| Override permissão | ✅ | ❌ | ❌ |
| Gerenciar roles | ✅ | ❌ | ❌ |
| Gerenciar módulos | ✅ | ❌ | ❌ |

*Admin só pode gerenciar usuários das **suas** lojas

### Soft Delete

| Entidade | Comportamento |
|----------|---------------|
| Usuários | Desativados (`active = false`) |
| Lojas | Soft delete (`deleted_at`) |

---

## 10. Audit Logs

### Audit Log de Permissões

```http
GET /api/v1/admin/users/{id}/permissions/audit-log
```

**Response:**
```json
{
  "user_id": 1,
  "user_name": "João Vendedor",
  "entries": [
    {
      "permission": "capas.view-global",
      "type": "grant",
      "is_active": true,
      "granted_by": "Admin Maria",
      "reason": "Cobertura de férias",
      "expires_at": "2026-01-20T23:59:59Z",
      "created_at": "2026-01-15T10:00:00Z"
    }
  ],
  "total": 5
}
```

### Audit Logs Gerais

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/api/v1/admin/audit-logs` | Listar logs |
| GET | `/api/v1/admin/audit-logs/stats` | Estatísticas |
| GET | `/api/v1/admin/audit-logs/{id}` | Detalhes |

---

## 11. Tipos TypeScript

```typescript
// ============================================
// ENUMS
// ============================================

type StoreRole = 'admin' | 'gerente' | 'conferente' | 'estoquista' | 'vendedor';

type OverrideType = 'grant' | 'deny';

type PermissionType = 'ability' | 'screen' | 'feature';

// ============================================
// INTERFACES BASE
// ============================================

interface Role {
  id: number;
  name: string;
  display_name: string;
  description?: string;
  level: number;
  is_system: boolean;
  permissions_count?: number;
}

interface Permission {
  id: number;
  name: string;
  display_name: string;
  description?: string;
  type: PermissionType;
  module: string;
}

interface StoreBinding {
  id: number;
  name: string;
  city?: string;
  role: StoreRole;
}

interface User {
  id: number;
  name: string;
  email: string;
  active: boolean;
  is_super_admin: boolean;
  is_global_admin?: boolean;
  has_fabrica_access?: boolean;
  
  // Perfil
  whatsapp?: string;
  birth_date?: string;
  hire_date?: string;
  avatar_url?: string;
  instagram?: string;
  cpf?: string;
  pix_key?: string;
  
  // Endereço
  zip_code?: string;
  street?: string;
  number?: string;
  complement?: string;
  neighborhood?: string;
  city?: string;
  state?: string;
  
  // Relacionamentos
  stores: StoreBinding[];
  roles?: string[];
  
  // Timestamps
  created_at: string;
  updated_at?: string;
}

interface Store {
  id: number;
  name: string;
  codigo?: string;
  city: string;
  state?: string;
  active: boolean;
  address?: string;
  neighborhood?: string;
  zip_code?: string;
  phone?: string;
  whatsapp?: string;
  instagram?: string;
  cnpj?: string;
  photo_url?: string;
  bio_enabled?: boolean;
  opening_hours?: OpeningHours;
  users_count?: number;
}

interface UserStoreRole {
  id: number;
  role: {
    id: number;
    name: string;
    display_name: string;
    level: number;
  };
  store: {
    id: number;
    name: string;
  } | null;
  is_global: boolean;
  created_at: string;
}

interface UserPermissionOverride {
  id: number;
  permission: {
    id: number;
    name: string;
    display_name: string;
    type: PermissionType;
    module: string;
  };
  type: OverrideType;
  store?: { id: number; name: string } | null;
  is_global: boolean;
  expires_at?: string;
  reason?: string;
  created_at: string;
}

interface StorePermissionOverride {
  id: number;
  permission: Permission;
  granted: boolean;
  created_at: string;
  updated_at: string;
}

// ============================================
// REQUESTS
// ============================================

interface CreateUserRequest {
  name: string;
  email: string;
  password: string;
  active?: boolean;
  is_super_admin?: boolean;
  stores?: Array<{ store_id: number; role: StoreRole }>;
  whatsapp?: string;
  birth_date?: string;
  hire_date?: string;
  // ... outros campos opcionais
}

interface UpdateUserRequest {
  name?: string;
  email?: string;
  password?: string;
  active?: boolean;
  is_super_admin?: boolean;
  // ... outros campos opcionais
}

interface BulkAddStoresRequest {
  stores: Array<{ store_id: number; role: StoreRole }>;
}

interface BulkUpdateStoresRequest {
  role: StoreRole;
  store_ids: number[];
}

interface BulkRemoveStoresRequest {
  store_ids: number[];
}

interface SyncStoresRequest {
  stores: Array<{ store_id: number; role: StoreRole }>;
}

interface AssignRoleRequest {
  role_id: number;
  store_id?: number | null;  // null = global
}

interface SyncRolesRequest {
  assignments: Array<{ role_id: number; store_id?: number | null }>;
}

interface CreatePermissionOverrideRequest {
  permission_id: number;
  type: OverrideType;
  store_id?: number | null;
  expires_at?: string;
  reason?: string;
}

interface CreateStorePermissionOverrideRequest {
  permission_id: number;
  granted: boolean;
}

// ============================================
// RESPONSES
// ============================================

interface ApiResponse<T> {
  data: T;
  meta?: PaginationMeta;
}

interface PaginationMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
}

interface BulkOperationResponse {
  message: string;
  created?: number[];
  skipped?: number[];
  updated_count?: number;
  deleted_count?: number;
}
```

---

## 12. React Query Hooks

```typescript
// hooks/useUsers.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

// ============================================
// USERS
// ============================================

export function useUsers(filters: UserFilters = {}) {
  return useQuery({
    queryKey: ['admin', 'users', filters],
    queryFn: () => api.get('/admin/users', { params: filters }),
  });
}

export function useUser(id: number) {
  return useQuery({
    queryKey: ['admin', 'users', id],
    queryFn: () => api.get(`/admin/users/${id}`),
    enabled: !!id,
  });
}

export function useCreateUser() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateUserRequest) => api.post('/admin/users', data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'users'] }),
  });
}

export function useUpdateUser(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: UpdateUserRequest) => api.patch(`/admin/users/${id}`, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'users'] });
      queryClient.invalidateQueries({ queryKey: ['admin', 'users', id] });
    },
  });
}

export function useDeleteUser(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => api.delete(`/admin/users/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'users'] }),
  });
}

// ============================================
// USER STORES
// ============================================

export function useSyncUserStores(userId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: SyncStoresRequest) => api.put(`/admin/users/${userId}/stores`, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'users'] });
      queryClient.invalidateQueries({ queryKey: ['admin', 'users', userId] });
    },
  });
}

export function useBulkAddUserStores(userId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: BulkAddStoresRequest) => api.post(`/admin/users/${userId}/stores/bulk`, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'users'] });
      queryClient.invalidateQueries({ queryKey: ['admin', 'users', userId] });
    },
  });
}

export function useBulkUpdateUserStores(userId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: BulkUpdateStoresRequest) => api.patch(`/admin/users/${userId}/stores/bulk`, data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'users', userId] }),
  });
}

export function useBulkRemoveUserStores(userId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: BulkRemoveStoresRequest) => api.delete(`/admin/users/${userId}/stores/bulk`, { data }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'users', userId] }),
  });
}

// ============================================
// ROLES
// ============================================

export function useRoles() {
  return useQuery({
    queryKey: ['admin', 'roles'],
    queryFn: () => api.get('/admin/roles'),
  });
}

export function useAvailableRoles() {
  return useQuery({
    queryKey: ['admin', 'roles', 'available'],
    queryFn: () => api.get('/admin/roles/available'),
  });
}

export function useUserRoles(userId: number) {
  return useQuery({
    queryKey: ['admin', 'users', userId, 'roles'],
    queryFn: () => api.get(`/admin/users/${userId}/roles`),
    enabled: !!userId,
  });
}

export function useAssignRole(userId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: AssignRoleRequest) => api.post(`/admin/users/${userId}/roles`, data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'users', userId, 'roles'] }),
  });
}

export function useSyncRoles(userId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: SyncRolesRequest) => api.put(`/admin/users/${userId}/roles/sync`, data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'users', userId, 'roles'] }),
  });
}

export function useCloneRole(roleId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: { name: string; display_name: string; description?: string }) => 
      api.post(`/admin/roles/${roleId}/clone`, data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'roles'] }),
  });
}

// ============================================
// PERMISSIONS
// ============================================

export function usePermissions(filters?: { type?: string; module?: string; group_by?: string }) {
  return useQuery({
    queryKey: ['admin', 'permissions', filters],
    queryFn: () => api.get('/admin/permissions', { params: filters }),
  });
}

export function usePermissionsGrouped() {
  return useQuery({
    queryKey: ['admin', 'permissions', 'grouped'],
    queryFn: () => api.get('/admin/permissions/grouped'),
  });
}

export function usePreviewPermissionChanges() {
  return useMutation({
    mutationFn: (data: { user_id: number; add_permissions: string[]; remove_permissions: string[] }) =>
      api.post('/admin/permissions/preview', data),
  });
}

export function useBulkGrantPermissions() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: { user_ids: number[]; permissions: string[]; expires_at?: string; reason?: string }) =>
      api.post('/admin/permissions/bulk-grant', data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin'] }),
  });
}

// ============================================
// PERMISSION OVERRIDES
// ============================================

export function useUserPermissionOverrides(userId: number) {
  return useQuery({
    queryKey: ['admin', 'users', userId, 'permission-overrides'],
    queryFn: () => api.get(`/admin/users/${userId}/permission-overrides`),
    enabled: !!userId,
  });
}

export function useEffectivePermissions(userId: number) {
  return useQuery({
    queryKey: ['admin', 'users', userId, 'permission-overrides', 'effective'],
    queryFn: () => api.get(`/admin/users/${userId}/permission-overrides/effective`),
    enabled: !!userId,
  });
}

export function useCreatePermissionOverride(userId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: CreatePermissionOverrideRequest) =>
      api.post(`/admin/users/${userId}/permission-overrides`, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'users', userId, 'permission-overrides'] });
    },
  });
}

export function useClearPermissionOverrides(userId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (storeId?: number) =>
      api.delete(`/admin/users/${userId}/permission-overrides/clear`, { params: { store_id: storeId } }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'users', userId, 'permission-overrides'] });
    },
  });
}

// ============================================
// STORES
// ============================================

export function useStores(filters?: { search?: string; active?: boolean }) {
  return useQuery({
    queryKey: ['admin', 'stores', filters],
    queryFn: () => api.get('/admin/stores', { params: filters }),
  });
}

export function useStore(id: number) {
  return useQuery({
    queryKey: ['admin', 'stores', id],
    queryFn: () => api.get(`/admin/stores/${id}`),
    enabled: !!id,
  });
}

export function useStoreUsers(storeId: number) {
  return useQuery({
    queryKey: ['admin', 'stores', storeId, 'users'],
    queryFn: () => api.get(`/admin/stores/${storeId}/users`),
    enabled: !!storeId,
  });
}

export function useBindUserToStore(storeId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: { user_id: number; role: StoreRole }) =>
      api.post(`/admin/stores/${storeId}/users`, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'stores', storeId, 'users'] });
    },
  });
}

// ============================================
// STORE PERMISSION OVERRIDES
// ============================================

export function useStorePermissionOverrides(storeId: number) {
  return useQuery({
    queryKey: ['admin', 'stores', storeId, 'permission-overrides'],
    queryFn: () => api.get(`/admin/stores/${storeId}/permission-overrides`),
    enabled: !!storeId,
  });
}

export function useCreateStorePermissionOverride(storeId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateStorePermissionOverrideRequest) =>
      api.post(`/admin/stores/${storeId}/permission-overrides`, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'stores', storeId, 'permission-overrides'] });
    },
  });
}

// ============================================
// AUDIT
// ============================================

export function useUserPermissionsAuditLog(userId: number) {
  return useQuery({
    queryKey: ['admin', 'users', userId, 'permissions', 'audit-log'],
    queryFn: () => api.get(`/admin/users/${userId}/permissions/audit-log`),
    enabled: !!userId,
  });
}
```

---

## 📌 Resumo de Diferenças vs Documento Anterior

| Item | Status | Observação |
|------|--------|------------|
| Hierarquia de Roles | ✅ ATUALIZADO | 7 roles (incluindo estoquista) com levels corretos |
| Store Permission Overrides | ✅ NOVO | Endpoints para overrides por loja |
| UserStoreRole | ✅ DOCUMENTADO | Tabela separada para roles global/por-loja |
| Audit Logs | ✅ EXPANDIDO | Endpoints de auditoria geral |
| Campos de Loja | ✅ COMPLETO | Todos os campos incluindo opening_hours |
| Types TypeScript | ✅ COMPLETO | Interfaces atualizadas |
| React Query Hooks | ✅ COMPLETO | Hooks para todas as operações |

---

*Backend Team - MaisCapinhas ERP - 16/01/2026 21:20*
