# 📘 Guia de API - Área de Configurações (Frontend)

**Data**: 2026-01-08  
**Versão**: 1.0  
**Contexto**: Integração das telas de Config com a API Backend

---

## 📋 Índice

1. [CRUD Usuários](#1-crud-usuários)
2. [Upload de Avatar](#2-upload-de-avatar-usuário)
3. [CRUD Lojas](#3-crud-lojas-stores)
4. [Foto da Loja](#4-foto-da-loja)
5. [Vincular Usuário ↔ Loja](#5-vincular-usuário--loja)
6. [Metas Mensais](#6-metas-mensais)
7. [Tabela de Bônus](#7-tabela-de-bônus)
8. [Regras de Comissão](#8-regras-de-comissão)
9. [Logs de Auditoria](#9-logs-de-auditoria)
10. [TypeScript Schemas](#10-typescript-schemas)
11. [Exemplos de Implementação](#11-exemplos-de-implementação)

---

## 1. CRUD Usuários

### Endpoints

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| **GET** | `/admin/users` | Listar todos os usuários |
| **POST** | `/admin/users` | Criar novo usuário |
| **GET** | `/admin/users/{id}` | Ver detalhes do usuário |
| **PUT** | `/admin/users/{id}` | Atualizar usuário |
| **DELETE** | `/admin/users/{id}` | Desativar usuário (soft delete) |

### GET `/admin/users` - Listagem

**Query Parameters:**

| Param | Tipo | Obrigatório | Descrição |
|-------|------|-------------|-----------|
| `search` | string | ❌ | Busca parcial por nome OU email (case insensitive) |
| `active` | boolean | ❌ | `true` = ativos, `false` = inativos |
| `store_id` | integer | ❌ | Filtrar usuários vinculados a esta loja |
| `per_page` | integer | ❌ | Itens por página (1-100, default: 25) |

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Admin Master",
      "email": "admin@maiscapinhas.com.br",
      "active": true,
      "created_at": "2026-01-01T00:00:00Z",
      "stores": [
        { "store_id": 1, "store_name": "Mais Capinhas Tijucas", "role": "admin" },
        { "store_id": 2, "store_name": "Mais Capinhas Itapema", "role": "admin" }
      ]
    }
  ],
  "meta": { "current_page": 1, "per_page": 25, "total": 10, "last_page": 1 }
}
```

### POST `/admin/users` - Criar

**Request Body:**
```json
{
  "name": "João Silva Santos",
  "email": "joao.silva@maiscapinhas.com.br",
  "password": "senha123456",
  "active": true,
  "stores": [
    { "store_id": 1, "role": "vendedor" }
  ]
}
```

| Campo | Tipo | Obrigatório | Validação |
|-------|------|-------------|-----------|
| `name` | string | ✅ | Nome completo |
| `email` | string | ✅ | Único no sistema |
| `password` | string | ✅ | Mínimo 8 caracteres |
| `active` | boolean | ❌ | Default: `true` |
| `stores` | array | ❌ | Vínculos opcionais na criação |
| `stores[].store_id` | integer | ✅* | ID da loja existente |
| `stores[].role` | string | ✅* | `admin`, `gerente`, `conferente`, `vendedor` |

**Response (201):**
```json
{
  "data": {
    "id": 11,
    "name": "João Silva Santos",
    "email": "joao.silva@maiscapinhas.com.br",
    "active": true,
    "created_at": "2026-01-07T17:00:00Z",
    "stores": [
      { "store_id": 1, "store_name": "Mais Capinhas Tijucas", "role": "vendedor" }
    ]
  }
}
```

### PUT `/admin/users/{id}` - Atualizar

**Request Body (todos opcionais):**
```json
{
  "name": "João da Silva Atualizado",
  "email": "joao.novo@maiscapinhas.com.br",
  "password": "novasenha123",
  "active": true
}
```

> ⚠️ Para gerenciar vínculos com lojas, use os endpoints de vínculos (`/admin/stores/{store}/users`)

### DELETE `/admin/users/{id}` - Desativar

**Comportamento:**
- **Soft delete**: Define `active = false`
- **Revoga tokens**: Todos os tokens do usuário são invalidados
- **Mantém histórico**: Dados de vendas/fechamentos são preservados
- **Reativação**: Via `PUT /admin/users/{id}` com `{ "active": true }`

**Erro possível:**
```json
{
  "message": "Você não pode desativar seu próprio usuário."
}
```

---

## 2. Upload de Avatar (Usuário)

### Endpoint

```
PUT /users/{id}/avatar
Content-Type: multipart/form-data
```

### Validações

| Aspecto | Especificação |
|---------|---------------|
| **Campo** | `avatar` |
| **Formatos** | `jpg`, `jpeg`, `png`, `webp` |
| **Tamanho máximo** | 2MB |
| **Dimensão mínima** | 200x200 pixels |
| **Quem pode** | O próprio usuário OU Admin |

### Request (multipart/form-data)

```http
PUT /users/5/avatar
Content-Type: multipart/form-data

avatar: [arquivo de imagem]
```

### Response (200)

```json
{
  "data": {
    "user_id": 5,
    "avatar_url": "https://api.maiscapinhas.com.br/storage/users/5/avatar.jpg"
  }
}
```

### Remover Avatar

```http
PUT /users/5/avatar
Content-Type: application/json

{ "remove": true }
```

### URL Retornada

- **Tipo**: URL completa (não path relativo)
- **Storage**: Local storage via Laravel (não CDN/S3)
- **Formato**: URL pública acessível diretamente

---

## 3. CRUD Lojas (Stores)

### Endpoints

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| **GET** | `/admin/stores` | Listar todas as lojas (admin) |
| **POST** | `/admin/stores` | Criar nova loja |
| **GET** | `/admin/stores/{id}` | Ver detalhes da loja |
| **PUT** | `/admin/stores/{id}` | Atualizar loja |
| **DELETE** | `/admin/stores/{id}` | Desativar loja (soft delete) |

### GET `/admin/stores` - Listagem

**Query Parameters:**

| Param | Tipo | Descrição |
|-------|------|-----------|
| `search` | string | Busca por nome OU cidade |
| `active` | boolean | Filtrar por status |
| `per_page` | integer | Itens por página (default: 25) |

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Mais Capinhas Tijucas",
      "city": "Tijucas",
      "active": true,
      "users_count": 5,
      "created_at": "2026-01-01T00:00:00Z"
    }
  ],
  "meta": { "current_page": 1, "per_page": 25, "total": 3, "last_page": 1 }
}
```

### POST `/admin/stores` - Criar

**Request Body:**
```json
{
  "name": "Mais Capinhas Shopping Center",
  "city": "Florianópolis",
  "active": true
}
```

| Campo | Tipo | Obrigatório |
|-------|------|-------------|
| `name` | string | ✅ |
| `city` | string | ✅ |
| `active` | boolean | ❌ (default: true) |

> ⚠️ **Nota**: Os campos `codigo`, `address`, `phone`, `troco_padrao` atualmente **não são editáveis** via API de admin. Apenas `name`, `city` e `active`.

### GET `/admin/stores/{id}` - Detalhes

**Response:**
```json
{
  "data": {
    "id": 1,
    "name": "Mais Capinhas Tijucas",
    "city": "Tijucas",
    "active": true,
    "created_at": "2026-01-01T00:00:00Z",
    "users": [
      { "user_id": 2, "user_name": "Carlos Gerente", "user_email": "carlos@test.com", "role": "gerente" },
      { "user_id": 6, "user_name": "João Vendedor", "user_email": "joao@test.com", "role": "vendedor" }
    ]
  }
}
```

### DELETE `/admin/stores/{id}` - Desativar

**Comportamento:**
- **Soft delete**: Define `active = false`
- **Dados mantidos**: Vendas, turnos, fechamentos preservados
- **Usuários**: Vínculos são mantidos (não remove `store_users`)
- **Reativação**: Via `PUT /admin/stores/{id}` com `{ "active": true }`

---

## 4. Foto da Loja

### Endpoint

```
PUT /stores/{id}/photo
Content-Type: multipart/form-data
```

### Validações

| Aspecto | Especificação |
|---------|---------------|
| **Campo** | `photo` |
| **Formatos** | `jpg`, `jpeg`, `png`, `webp` |
| **Tamanho máximo** | 5MB |
| **Dimensão mínima** | 800x600 pixels |
| **Quem pode** | Admin (qualquer loja) OU Gerente (sua loja) |

### Response

```json
{
  "data": {
    "store_id": 1,
    "photo_url": "https://api.maiscapinhas.com.br/storage/stores/1/photo.jpg"
  }
}
```

### Remover Foto

```json
{ "remove": true }
```

---

## 5. Vincular Usuário ↔ Loja

### Endpoints

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| **GET** | `/admin/stores/{store}/users` | Listar usuários da loja |
| **POST** | `/admin/stores/{store}/users` | Vincular usuário |
| **PUT** | `/admin/stores/{store}/users/{user}` | Alterar role |
| **DELETE** | `/admin/stores/{store}/users/{user}` | Remover vínculo |

### GET `/admin/stores/{store}/users` - Listar

**Response:**
```json
{
  "data": [
    {
      "user_id": 2,
      "user_name": "Carlos Gerente",
      "user_email": "carlos.gerente@maiscapinhas.com.br",
      "user_active": true,
      "role": "gerente",
      "created_at": "2026-01-01T00:00:00Z"
    }
  ]
}
```

### POST `/admin/stores/{store}/users` - Vincular

**Request:**
```json
{
  "user_id": 8,
  "role": "vendedor"
}
```

| Campo | Tipo | Valores permitidos |
|-------|------|--------------------|
| `user_id` | integer | ID de usuário existente |
| `role` | string | `admin`, `gerente`, `conferente`, `vendedor` |

**Response (201):**
```json
{
  "data": {
    "user_id": 8,
    "store_id": 1,
    "role": "vendedor"
  }
}
```

### PUT `/admin/stores/{store}/users/{user}` - Alterar Role

**Request:**
```json
{
  "role": "gerente"
}
```

### DELETE - Remover Vínculo

**Regras:**
- Não pode remover seu próprio vínculo
- Dados históricos são mantidos (vendas, fechamentos)

---

## 6. Metas Mensais

### Endpoints

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| **GET** | `/goals/monthly` | Listar metas |
| **POST** | `/goals/monthly` | Criar meta |
| **GET** | `/goals/monthly/{id}` | Ver meta |
| **PUT** | `/goals/monthly/{id}` | Atualizar meta |
| **DELETE** | `/goals/monthly/{id}` | Excluir meta |
| **PUT** | `/goals/monthly/{id}/splits` | Definir splits |

### GET `/goals/monthly` - Listar

**Query Parameters:**

| Param | Tipo | Descrição |
|-------|------|-----------|
| `store_id` | integer | Filtrar por loja |
| `per_page` | integer | Itens por página |

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "store_id": 1,
      "month": "2026-01",
      "goal_amount": 50000.00,
      "active": true,
      "store": { "id": 1, "name": "Mais Capinhas Tijucas" },
      "splits": [
        { "user_id": 5, "percent": 40, "user": { "id": 5, "name": "João" } },
        { "user_id": 6, "percent": 35, "user": { "id": 6, "name": "Maria" } },
        { "user_id": 7, "percent": 25, "user": { "id": 7, "name": "Pedro" } }
      ]
    }
  ]
}
```

### POST `/goals/monthly` - Criar

**Request:**
```json
{
  "store_id": 1,
  "month": "2026-02",
  "goal_amount": 60000.00,
  "active": true
}
```

| Campo | Tipo | Formato | Obrigatório |
|-------|------|---------|-------------|
| `store_id` | integer | ID válido | ✅ |
| `month` | string | `YYYY-MM` | ✅ |
| `goal_amount` | number | Decimal | ✅ |
| `active` | boolean | - | ❌ |

> ⚠️ **Regra**: Não pode haver duas metas para a mesma loja + mês (retorna 409 Conflict)

### PUT `/goals/monthly/{id}/splits` - Definir Splits

**Request:**
```json
{
  "splits": [
    { "user_id": 5, "percent": 40 },
    { "user_id": 6, "percent": 35 },
    { "user_id": 7, "percent": 25 }
  ]
}
```

| Campo | Tipo | Validação |
|-------|------|-----------|
| `splits[].user_id` | integer | ID de usuário existente |
| `splits[].percent` | number | 0-100 |

> ⚠️ **Validação Backend**: A soma dos `percent` **deve ser exatamente 100%**

**Quem pode**: Apenas gerentes/admins da loja

---

## 7. Tabela de Bônus

### Endpoints

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| **GET** | `/rules/bonus` | Listar regras |
| **POST** | `/rules/bonus` | Criar regra |
| **GET** | `/rules/bonus/{id}` | Ver regra |
| **PUT** | `/rules/bonus/{id}` | Atualizar |
| **DELETE** | `/rules/bonus/{id}` | Excluir |

### GET `/rules/bonus` - Listar

**Query Parameters:**

| Param | Tipo | Descrição |
|-------|------|-----------|
| `store_id` | integer | Filtrar por loja específica |
| `per_page` | integer | Itens por página |

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "store_id": null,
      "name": "Tabela Padrão",
      "config_json": [
        { "min_sales": 500, "bonus": 10 },
        { "min_sales": 1000, "bonus": 25 },
        { "min_sales": 1500, "bonus": 40 }
      ],
      "effective_from": "2026-01-01",
      "effective_to": null,
      "version": 1,
      "active": true,
      "store": null
    },
    {
      "id": 2,
      "store_id": 1,
      "name": "Tabela Premium Tijucas",
      "config_json": [...],
      "store": { "id": 1, "name": "Mais Capinhas Tijucas" }
    }
  ]
}
```

### POST `/rules/bonus` - Criar

**Request:**
```json
{
  "store_id": null,
  "name": "Tabela Bônus 2026",
  "config_json": [
    { "min_sales": 500, "bonus": 10 },
    { "min_sales": 1000, "bonus": 25 },
    { "min_sales": 1500, "bonus": 40 }
  ],
  "effective_from": "2026-02-01",
  "effective_to": null,
  "active": true
}
```

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `store_id` | integer\|null | `null` = regra global |
| `name` | string | Nome identificador |
| `config_json` | array | Faixas de bônus |
| `config_json[].min_sales` | number | Valor mínimo de vendas |
| `config_json[].bonus` | number | Valor do bônus em R$ |
| `effective_from` | string | Data de início (YYYY-MM-DD) |
| `effective_to` | string\|null | Data fim (null = sem fim) |
| `active` | boolean | Ativa/inativa |

### Hierarquia de Regras

1. **Regra específica da loja** (`store_id` preenchido) tem prioridade
2. Se não houver, usa **regra global** (`store_id: null`)
3. Regra mais recente (`effective_from` maior) tem prioridade
4. Pode haver múltiplas regras ativas

---

## 8. Regras de Comissão

### Endpoints

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| **GET** | `/rules/commission` | Listar regras |
| **POST** | `/rules/commission` | Criar regra |
| **GET** | `/rules/commission/{id}` | Ver regra |
| **PUT** | `/rules/commission/{id}` | Atualizar |
| **DELETE** | `/rules/commission/{id}` | Excluir |

### Estrutura da Regra

```json
{
  "id": 1,
  "store_id": null,
  "name": "Comissão Padrão",
  "config_json": [
    { "min_rate": 0, "commission_rate": 1.0 },
    { "min_rate": 80, "commission_rate": 2.0 },
    { "min_rate": 100, "commission_rate": 3.0 },
    { "min_rate": 120, "commission_rate": 4.0 }
  ],
  "effective_from": "2026-01-01",
  "effective_to": null,
  "version": 1,
  "active": true
}
```

### Significado dos Campos

| Campo | Significado |
|-------|-------------|
| `min_rate` | % mínimo de atingimento da meta mensal |
| `commission_rate` | % de comissão sobre as vendas |

**Exemplo de cálculo:**
- Vendedor atingiu 85% da meta
- Regra aplicada: `{ min_rate: 80, commission_rate: 2.0 }`
- Comissão = Total Vendas × 2.0%

### Mesma Lógica Global/Loja

Sim, `store_id: null` = regra global para todas as lojas.

---

## 9. Logs de Auditoria

### Endpoints

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| **GET** | `/admin/audit-logs` | Listar logs |
| **GET** | `/admin/audit-logs/stats` | Estatísticas |
| **GET** | `/admin/audit-logs/{id}` | Ver log |

### GET `/admin/audit-logs` - Listar

**Query Parameters:**

| Param | Tipo | Descrição |
|-------|------|-----------|
| `from` | date | Data inicial (YYYY-MM-DD) |
| `to` | date | Data final (YYYY-MM-DD) |
| `causer_id` | integer | Usuário que executou |
| `event` | string | Evento (suporta wildcard: `auth.*`) |
| `log_name` | string | Domínio: `auth`, `cash`, `rules`, `goals` |
| `store_id` | integer | Loja relacionada |
| `subject_type` | string | Tipo de entidade |
| `subject_id` | integer | ID da entidade |
| `per_page` | integer | Itens por página (1-100) |

**Response:**
```json
{
  "data": [
    {
      "id": 150,
      "event": "auth.login",
      "action": "login",
      "log_name": "auth",
      "created_at": "2026-01-07T18:00:00Z",
      "causer": { "id": 1, "name": "Admin", "email": "admin@test.com" },
      "subject": { "type": "User", "id": 1 },
      "store": null,
      "context": { "request_id": "abc-123", "ip": "192.168.1.1" },
      "properties": { "auth_mode": "bearer", "device_name": "postman" }
    }
  ],
  "meta": { "current_page": 1, "per_page": 25, "total": 150 }
}
```

### Eventos Registrados

| Domínio | Eventos |
|---------|---------|
| `auth` | `login`, `logout`, `login_failed` |
| `cash` | `cash_closing.submit`, `approve`, `reject` |
| `rules` | `bonus/commission.create`, `update`, `delete` |
| `goals` | `monthly.create`, `update`, `splits.set` |
| `sales` | `create`, `update`, `delete` |
| `admin` | `user/store.create`, `update`, `delete` |

### GET `/admin/audit-logs/stats` - Estatísticas

**Response:**
```json
{
  "data": {
    "total_logs": 1500,
    "by_log_name": { "auth": 500, "cash": 800, "rules": 200 },
    "by_action": { "login": 300, "submit": 500, "approve": 300 },
    "unique_users": 15,
    "period": { "from": "2026-01-01", "to": "2026-01-31" }
  }
}
```

---

## 10. TypeScript Schemas

```typescript
// ============ ADMIN USERS ============
interface CreateUserRequest {
  name: string;
  email: string;
  password: string;
  active?: boolean;
  stores?: Array<{
    store_id: number;
    role: 'admin' | 'gerente' | 'conferente' | 'vendedor';
  }>;
}

interface UpdateUserRequest {
  name?: string;
  email?: string;
  password?: string;
  active?: boolean;
}

interface UserResponse {
  id: number;
  name: string;
  email: string;
  active: boolean;
  created_at: string;
  stores: Array<{
    store_id: number;
    store_name: string;
    role: 'admin' | 'gerente' | 'conferente' | 'vendedor';
  }>;
}

// ============ ADMIN STORES ============
interface CreateStoreRequest {
  name: string;
  city: string;
  active?: boolean;
}

interface UpdateStoreRequest {
  name?: string;
  city?: string;
  active?: boolean;
}

interface StoreResponse {
  id: number;
  name: string;
  city: string;
  active: boolean;
  users_count?: number;
  created_at: string;
  users?: StoreUserBinding[];
}

// ============ STORE-USER BINDINGS ============
interface StoreUserBinding {
  user_id: number;
  user_name: string;
  user_email: string;
  user_active: boolean;
  role: 'admin' | 'gerente' | 'conferente' | 'vendedor';
  created_at: string;
}

interface CreateBindingRequest {
  user_id: number;
  role: 'admin' | 'gerente' | 'conferente' | 'vendedor';
}

interface UpdateBindingRequest {
  role: 'admin' | 'gerente' | 'conferente' | 'vendedor';
}

// ============ MONTHLY GOALS ============
interface MonthlyGoal {
  id: number;
  store_id: number;
  month: string; // YYYY-MM
  goal_amount: number;
  active: boolean;
  store: { id: number; name: string };
  splits: GoalSplit[];
}

interface GoalSplit {
  user_id: number;
  percent: number;
  user: { id: number; name: string };
}

interface CreateGoalRequest {
  store_id: number;
  month: string; // YYYY-MM
  goal_amount: number;
  active?: boolean;
}

interface SetSplitsRequest {
  splits: Array<{
    user_id: number;
    percent: number; // soma deve = 100
  }>;
}

// ============ BONUS RULES ============
interface BonusRule {
  id: number;
  store_id: number | null;
  name: string;
  config_json: BonusTier[];
  effective_from: string;
  effective_to: string | null;
  version: number;
  active: boolean;
  store: { id: number; name: string } | null;
}

interface BonusTier {
  min_sales: number;
  bonus: number;
}

interface CreateBonusRuleRequest {
  store_id?: number | null;
  name: string;
  config_json: BonusTier[];
  effective_from: string;
  effective_to?: string | null;
  active?: boolean;
}

// ============ COMMISSION RULES ============
interface CommissionRule {
  id: number;
  store_id: number | null;
  name: string;
  config_json: CommissionTier[];
  effective_from: string;
  effective_to: string | null;
  version: number;
  active: boolean;
  store: { id: number; name: string } | null;
}

interface CommissionTier {
  min_rate: number;       // % de atingimento da meta
  commission_rate: number; // % de comissão sobre vendas
}

interface CreateCommissionRuleRequest {
  store_id?: number | null;
  name: string;
  config_json: CommissionTier[];
  effective_from: string;
  effective_to?: string | null;
  active?: boolean;
}

// ============ AUDIT LOGS ============
interface AuditLog {
  id: number;
  event: string;
  action: string;
  log_name: string;
  created_at: string;
  causer: { id: number; name: string; email: string } | null;
  subject: { type: string; id: number } | null;
  store: { id: number; name: string } | null;
  context: Record<string, any>;
  properties: Record<string, any>;
}

interface AuditStats {
  total_logs: number;
  by_log_name: Record<string, number>;
  by_action: Record<string, number>;
  unique_users: number;
  period: { from: string | null; to: string | null };
}

// ============ AVATAR/PHOTO UPLOAD ============
interface AvatarResponse {
  user_id: number;
  avatar_url: string | null;
}

interface StorePhotoResponse {
  store_id: number;
  photo_url: string | null;
}

// ============ API WRAPPERS ============
interface ApiResponse<T> {
  data: T;
  meta?: {
    request_id?: string;
    timestamp?: string;
  };
}

interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    from: number;
    to: number;
  };
}
```

---

## 11. Exemplos de Implementação

### Service para Usuários

```typescript
// src/services/admin/users.service.ts
import api from '@/lib/api';

export const usersService = {
  async list(params?: {
    search?: string;
    active?: boolean;
    store_id?: number;
    per_page?: number;
  }) {
    const { data } = await api.get<PaginatedResponse<UserResponse>>('/admin/users', { params });
    return data;
  },

  async create(payload: CreateUserRequest) {
    const { data } = await api.post<ApiResponse<UserResponse>>('/admin/users', payload);
    return data.data;
  },

  async get(id: number) {
    const { data } = await api.get<ApiResponse<UserResponse>>(`/admin/users/${id}`);
    return data.data;
  },

  async update(id: number, payload: UpdateUserRequest) {
    const { data } = await api.put<ApiResponse<UserResponse>>(`/admin/users/${id}`, payload);
    return data.data;
  },

  async deactivate(id: number) {
    const { data } = await api.delete(`/admin/users/${id}`);
    return data;
  },

  async uploadAvatar(id: number, file: File) {
    const formData = new FormData();
    formData.append('avatar', file);
    const { data } = await api.put<ApiResponse<AvatarResponse>>(
      `/users/${id}/avatar`,
      formData,
      { headers: { 'Content-Type': 'multipart/form-data' } }
    );
    return data.data;
  },

  async removeAvatar(id: number) {
    const { data } = await api.put<ApiResponse<AvatarResponse>>(
      `/users/${id}/avatar`,
      { remove: true }
    );
    return data.data;
  }
};
```

### Service para Goals

```typescript
// src/services/goals.service.ts
import api from '@/lib/api';

export const goalsService = {
  async list(params?: { store_id?: number; per_page?: number }) {
    const { data } = await api.get<PaginatedResponse<MonthlyGoal>>('/goals/monthly', { params });
    return data;
  },

  async create(payload: CreateGoalRequest) {
    const { data } = await api.post<ApiResponse<MonthlyGoal>>('/goals/monthly', payload);
    return data.data;
  },

  async update(id: number, payload: Partial<CreateGoalRequest>) {
    const { data } = await api.put<ApiResponse<MonthlyGoal>>(`/goals/monthly/${id}`, payload);
    return data.data;
  },

  async setSplits(id: number, splits: SetSplitsRequest['splits']) {
    const { data } = await api.put<ApiResponse<MonthlyGoal>>(
      `/goals/monthly/${id}/splits`,
      { splits }
    );
    return data.data;
  },

  async delete(id: number) {
    await api.delete(`/goals/monthly/${id}`);
  }
};
```

### Hook para Upload de Imagem

```typescript
// src/hooks/useImageUpload.ts
import { useState } from 'react';

interface UseImageUploadOptions {
  maxSizeMB: number;
  minWidth?: number;
  minHeight?: number;
  allowedTypes: string[];
}

export function useImageUpload(options: UseImageUploadOptions) {
  const [error, setError] = useState<string | null>(null);
  const [preview, setPreview] = useState<string | null>(null);

  const validateFile = (file: File): Promise<boolean> => {
    return new Promise((resolve) => {
      setError(null);

      // Check type
      const ext = file.name.split('.').pop()?.toLowerCase();
      if (!options.allowedTypes.includes(ext || '')) {
        setError(`Formato inválido. Aceitos: ${options.allowedTypes.join(', ')}`);
        resolve(false);
        return;
      }

      // Check size
      if (file.size > options.maxSizeMB * 1024 * 1024) {
        setError(`Arquivo muito grande. Máximo: ${options.maxSizeMB}MB`);
        resolve(false);
        return;
      }

      // Check dimensions
      if (options.minWidth || options.minHeight) {
        const img = new Image();
        img.src = URL.createObjectURL(file);
        img.onload = () => {
          URL.revokeObjectURL(img.src);
          if (img.width < (options.minWidth || 0) || img.height < (options.minHeight || 0)) {
            setError(`Dimensão mínima: ${options.minWidth}x${options.minHeight}px`);
            resolve(false);
          } else {
            setPreview(URL.createObjectURL(file));
            resolve(true);
          }
        };
      } else {
        setPreview(URL.createObjectURL(file));
        resolve(true);
      }
    });
  };

  return { validateFile, error, preview, clearPreview: () => setPreview(null) };
}

// Uso para avatar
const avatarUpload = useImageUpload({
  maxSizeMB: 2,
  minWidth: 200,
  minHeight: 200,
  allowedTypes: ['jpg', 'jpeg', 'png', 'webp']
});

// Uso para foto da loja
const storePhotoUpload = useImageUpload({
  maxSizeMB: 5,
  minWidth: 800,
  minHeight: 600,
  allowedTypes: ['jpg', 'jpeg', 'png', 'webp']
});
```

---

## 📋 Resumo de Endpoints

| Recurso | GET | POST | PUT | DELETE |
|---------|-----|------|-----|--------|
| **Usuários** | `/admin/users` | `/admin/users` | `/admin/users/{id}` | `/admin/users/{id}` |
| **Avatar** | - | - | `/users/{id}/avatar` | - |
| **Lojas** | `/admin/stores` | `/admin/stores` | `/admin/stores/{id}` | `/admin/stores/{id}` |
| **Foto Loja** | - | - | `/stores/{id}/photo` | - |
| **Vínculos** | `/admin/stores/{id}/users` | `/admin/stores/{id}/users` | `/admin/stores/{id}/users/{uid}` | `/admin/stores/{id}/users/{uid}` |
| **Metas** | `/goals/monthly` | `/goals/monthly` | `/goals/monthly/{id}` | `/goals/monthly/{id}` |
| **Splits** | - | - | `/goals/monthly/{id}/splits` | - |
| **Bônus** | `/rules/bonus` | `/rules/bonus` | `/rules/bonus/{id}` | `/rules/bonus/{id}` |
| **Comissão** | `/rules/commission` | `/rules/commission` | `/rules/commission/{id}` | `/rules/commission/{id}` |
| **Auditoria** | `/admin/audit-logs` | - | - | - |
| **Stats** | `/admin/audit-logs/stats` | - | - | - |

---

## ⚠️ Pontos Importantes

1. **Roles são por loja** - Um usuário pode ter roles diferentes em lojas diferentes
2. **Soft delete** - Usuários e lojas são desativados, não excluídos
3. **Splits = 100%** - Backend valida que soma deve ser exatamente 100%
4. **store_id: null = Global** - Regras sem store_id aplicam a todas as lojas
5. **Prioridade de regras** - Específica da loja > Global, Mais recente > Mais antiga
6. **Upload via multipart** - Avatar e foto usam `multipart/form-data`
7. **URLs completas** - Storage retorna URL completa, não path relativo
8. **Quem pode criar metas/regras** - Apenas gerentes e admins (require-manager)
9. **Auto-proteção** - Não pode desativar/remover vínculo do próprio usuário

---

**Última atualização**: 2026-01-08
