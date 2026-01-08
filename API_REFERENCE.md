# 📖 MaisCapinhas ERP API - Referência para Frontend

> Guia completo de endpoints e schemas para integração frontend.

**Base URL:** `https://api.maiscapinhas.com.br/api/v1`

---

## 🔐 Autenticação

### Como Funciona

1. Faça `POST /auth/login` com email e senha
2. Receba um **Bearer Token**
3. Use o token em todas as requisições: `Authorization: Bearer {token}`

### Login

```http
POST /auth/login
Content-Type: application/json

{
  "email": "admin@maiscapinhas.com.br",
  "password": "password",
  "device_name": "web-app"  // opcional
}
```

**Response (200):**
```json
{
  "data": {
    "token": "1|abc123xyz...",
    "token_type": "Bearer",
    "user": {
      "id": 1,
      "name": "Admin Sistema",
      "email": "admin@maiscapinhas.com.br",
      "stores": [
        { "id": 1, "name": "Tijucas", "role": "admin" }
      ]
    }
  },
  "meta": {
    "request_id": "abc-123-uuid",
    "timestamp": "2026-01-08T10:00:00Z"
  }
}
```

### Logout

```http
POST /auth/logout
Authorization: Bearer {token}
```

### Usuário Atual

```http
GET /me
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "user": {
      "id": 1,
      "name": "Admin Sistema",
      "email": "admin@maiscapinhas.com.br",
      "avatar_url": "https://...",
      "stores": [
        { "id": 1, "name": "Tijucas", "role": "admin" },
        { "id": 2, "name": "Itapema", "role": "gerente" }
      ]
    }
  }
}
```

---

## 📋 Estrutura Padrão das Respostas

### Sucesso

```typescript
interface ApiResponse<T> {
  data: T;
  meta: {
    request_id: string;
    timestamp: string;
  };
}
```

### Sucesso Paginado

```typescript
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

### Erro

```typescript
interface ErrorResponse {
  message: string;
  errors?: Record<string, string[]>;  // erros de validação
}
```

**Códigos HTTP:**
| Código | Significado |
|--------|-------------|
| 200 | Sucesso |
| 201 | Criado |
| 401 | Não autenticado |
| 403 | Sem permissão |
| 404 | Não encontrado |
| 422 | Validação falhou |
| 500 | Erro servidor |

---

## 📡 Endpoints por Módulo

### 🏥 Health & Versão

| Método | Endpoint | Auth | Descrição |
|--------|----------|------|-----------|
| GET | `/health` | ❌ | Status da API |
| GET | `/version` | ❌ | Versão da API |

---

### 🔑 Autenticação

| Método | Endpoint | Auth | Descrição |
|--------|----------|------|-----------|
| POST | `/auth/login` | ❌ | Login |
| POST | `/auth/logout` | ✅ | Logout token atual |
| POST | `/auth/logout-all` | ✅ | Revogar todos tokens |
| POST | `/auth/forgot-password` | ❌ | Recuperar senha |
| POST | `/auth/reset-password` | ❌ | Redefinir senha |
| PUT | `/auth/password` | ✅ | Alterar senha |
| GET | `/me` | ✅ | Perfil do usuário |

---

### 🏪 Lojas

| Método | Endpoint | Auth | Descrição |
|--------|----------|------|-----------|
| GET | `/stores` | ✅ | Listar lojas do usuário |
| GET | `/stores/{id}` | ✅ | Detalhes da loja |
| GET | `/stores/{id}/sellers` | ✅ | Vendedores da loja |
| PUT | `/stores/{id}/photo` | ✅ | Upload foto fachada |

**Schema Store:**
```typescript
interface Store {
  id: number;
  name: string;
  codigo: string;
  address: string;
  phone: string;
  troco_padrao: number;
  photo_url: string | null;
  active: boolean;
}
```

---

### 💰 Vendas

| Método | Endpoint | Auth | Descrição |
|--------|----------|------|-----------|
| GET | `/sales` | ✅ | Listar vendas |
| POST | `/sales` | ✅ | Criar venda |
| GET | `/sales/{id}` | ✅ | Detalhes |
| PUT | `/sales/{id}` | ✅ | Atualizar (gerente+) |
| DELETE | `/sales/{id}` | ✅ | Excluir (admin) |

**Query Params (GET /sales):**
- `store_id` - Filtrar por loja
- `seller_id` - Filtrar por vendedor
- `from` / `to` - Período (YYYY-MM-DD)
- `per_page` - Itens por página

**Schema Sale:**
```typescript
interface Sale {
  id: number;
  store_id: number;
  seller_id: number;
  amount: number;
  payment_method: 'dinheiro' | 'pix' | 'credito' | 'debito';
  sold_at: string;
  created_at: string;
}
```

---

### 📦 Fechamento de Caixa

| Método | Endpoint | Auth | Descrição |
|--------|----------|------|-----------|
| GET | `/cash/shifts` | ✅ | Listar turnos |
| POST | `/cash/shifts` | ✅ | Criar turno |
| GET | `/cash/shifts/{id}` | ✅ | Ver turno |
| GET | `/cash/shifts/pending` | ✅ | Turnos pendentes |
| GET | `/cash/shifts/divergent` | ✅ | Turnos com divergência |
| GET | `/cash/closings/{shift}` | ✅ | Ver fechamento |
| POST | `/cash/closings/{shift}/submit` | ✅ | Enviar fechamento |
| POST | `/cash/closings/{shift}/approve` | ✅ | Aprovar |
| POST | `/cash/closings/{shift}/reject` | ✅ | Rejeitar |

**Schema CashShift:**
```typescript
interface CashShift {
  id: number;
  store_id: number;
  seller_id: number;
  date: string;
  shift_code: 'M' | 'T' | 'N';  // Manhã, Tarde, Noite
  status: 'open' | 'submitted' | 'approved' | 'rejected';
  system_total: number;
  real_total: number;
  divergence: number;
}
```

**Body para Submit:**
```typescript
interface SubmitClosingBody {
  lines: Array<{
    payment_method: 'dinheiro' | 'pix' | 'credito' | 'debito';
    system_amount: number;
    real_amount: number;
    justification?: string;  // obrigatório se divergência
  }>;
}
```

---

### 💵 Finanças - Bônus

| Método | Endpoint | Auth | Descrição |
|--------|----------|------|-----------|
| GET | `/finance/bonus` | ✅ | Extrato de bônus |
| GET | `/finance/bonus/seller/{id}` | ✅ | Bônus do vendedor |
| GET | `/finance/bonus/calculate` | ✅ | Simulador |

**Query Params (calculate):**
- `amount` - Valor de vendas para simular
- `store_id` - Loja (opcional)

**Response (calculate):**
```json
{
  "data": {
    "sales_amount": 1200.00,
    "bonus_value": 35.00,
    "tier_applied": { "min_sales": 1200, "bonus": 35 },
    "next_tier": { "min_sales": 1800, "bonus": 50, "gap": 600 }
  }
}
```

---

### 💵 Finanças - Comissão

| Método | Endpoint | Auth | Descrição |
|--------|----------|------|-----------|
| GET | `/finance/commission` | ✅ | Extrato de comissão |
| GET | `/finance/commission/seller/{id}` | ✅ | Comissão do vendedor |
| GET | `/finance/commission/projection/{id}` | ✅ | Projeção com cenários |

**Response (projection):**
```json
{
  "data": {
    "seller": { "id": 5, "name": "João" },
    "current": {
      "sales_mtd": 18500.00,
      "goal": 25000.00,
      "achievement_rate": 74.0,
      "current_tier": 2.0
    },
    "projection": {
      "optimistic": { "sales": 28500, "rate": 3.0, "commission": 855.00 },
      "realistic": { "sales": 24000, "rate": 2.0, "commission": 480.00 },
      "pessimistic": { "sales": 20000, "rate": 2.0, "commission": 400.00 }
    },
    "days_remaining": 8
  }
}
```

---

### 📊 Dashboards

| Método | Endpoint | Auth | Descrição |
|--------|----------|------|-----------|
| GET | `/dashboard/seller` | ✅ | Dashboard vendedor |
| GET | `/dashboard/store` | ✅ | Dashboard loja |
| GET | `/dashboard/admin` | ✅ | Dashboard admin |

**Response (seller):**
```json
{
  "data": {
    "today": {
      "total_sold": 850.00,
      "daily_goal": 1200.00,
      "achievement_rate": 70.83,
      "current_bonus": 20.00
    },
    "month": {
      "total_sold": 18500.00,
      "goal": 25000.00,
      "achievement_rate": 74.0
    },
    "gamification": {
      "next_bonus_tier": { "min_sales": 1000, "bonus": 35 },
      "gap_to_next": 150.00,
      "message": "Faltam R$ 150 para ganhar R$ 35!"
    }
  }
}
```

---

### 📈 Relatórios

| Método | Endpoint | Auth | Descrição |
|--------|----------|------|-----------|
| GET | `/reports/store-performance` | ✅ | Performance da loja |
| GET | `/reports/consolidated` | ✅ | Multi-lojas |
| GET | `/reports/cash-integrity` | ✅ | Integridade caixa |
| GET | `/reports/ranking` | ✅ | Ranking vendedores |

**Response (ranking):**
```json
{
  "data": {
    "period": "2026-01",
    "podium": [
      {
        "position": 1,
        "seller": { "id": 5, "name": "João", "avatar_url": "..." },
        "total_sold": 85000.00,
        "goal": 75000.00,
        "achievement_rate": 113.33,
        "bonus_accumulated": 450.00
      }
    ],
    "ranking": [...],
    "stats": {
      "total_sellers": 25,
      "above_goal": 12,
      "average_achievement": 92.5
    }
  }
}
```

---

### 👤 Usuários

| Método | Endpoint | Auth | Descrição |
|--------|----------|------|-----------|
| GET | `/users/birthdays` | ✅ | Aniversariantes do mês |
| PUT | `/users/{id}/avatar` | ✅ | Upload avatar |

**Upload Avatar (multipart/form-data):**
```
PUT /users/5/avatar
Content-Type: multipart/form-data

avatar: [arquivo jpg/png/webp, max 2MB, min 200x200]
```

---

### ⚙️ Regras (Admin/Gerente)

| Método | Endpoint | Auth | Descrição |
|--------|----------|------|-----------|
| GET | `/rules/bonus` | ✅ | Regras de bônus |
| POST | `/rules/bonus` | ✅ | Criar regra |
| PUT | `/rules/bonus/{id}` | ✅ | Atualizar |
| DELETE | `/rules/bonus/{id}` | ✅ | Excluir |
| GET | `/rules/commission` | ✅ | Regras comissão |
| POST | `/rules/commission` | ✅ | Criar regra |
| PUT | `/rules/commission/{id}` | ✅ | Atualizar |

**Schema BonusRule:**
```typescript
interface BonusRule {
  id: number;
  store_id: number | null;  // null = global
  name: string;
  config_json: Array<{ min_sales: number; bonus: number }>;
  valid_from: string;
  valid_to: string | null;
  active: boolean;
}
```

---

### 🎯 Metas

| Método | Endpoint | Auth | Descrição |
|--------|----------|------|-----------|
| GET | `/goals/monthly` | ✅ | Listar metas |
| POST | `/goals/monthly` | ✅ | Criar meta |
| GET | `/goals/monthly/{id}` | ✅ | Ver meta |
| PUT | `/goals/monthly/{id}` | ✅ | Atualizar |
| PUT | `/goals/monthly/{id}/splits` | ✅ | Definir splits |

**Body Splits:**
```json
{
  "splits": [
    { "user_id": 5, "percentage": 40 },
    { "user_id": 6, "percentage": 35 },
    { "user_id": 7, "percentage": 25 }
  ]
}
```
> ⚠️ Soma deve ser 100%

---

### 🔧 Admin

| Método | Endpoint | Auth | Descrição |
|--------|----------|------|-----------|
| GET | `/admin/users` | ✅ | Listar usuários |
| POST | `/admin/users` | ✅ | Criar usuário |
| GET | `/admin/users/{id}` | ✅ | Ver usuário |
| PUT | `/admin/users/{id}` | ✅ | Atualizar |
| DELETE | `/admin/users/{id}` | ✅ | Desativar |
| GET | `/admin/stores` | ✅ | Listar lojas |
| POST | `/admin/stores` | ✅ | Criar loja |
| GET | `/admin/stores/{id}/users` | ✅ | Usuários da loja |
| POST | `/admin/stores/{store}/users` | ✅ | Vincular usuário |
| GET | `/admin/audit-logs` | ✅ | Logs de auditoria |

---

## 🎨 TypeScript Interfaces

```typescript
// Usuário
interface User {
  id: number;
  name: string;
  email: string;
  phone?: string;
  avatar_url?: string;
  birth_date?: string;
  active: boolean;
  stores: StoreRole[];
}

interface StoreRole {
  id: number;
  name: string;
  role: 'admin' | 'gerente' | 'conferente' | 'vendedor';
}

// Venda
interface Sale {
  id: number;
  store_id: number;
  seller_id: number;
  amount: number;
  payment_method: PaymentMethod;
  sold_at: string;
  seller?: { id: number; name: string };
  store?: { id: number; name: string };
}

type PaymentMethod = 'dinheiro' | 'pix' | 'credito' | 'debito';

// Turno de Caixa
interface CashShift {
  id: number;
  store_id: number;
  seller_id: number;
  date: string;
  shift_code: 'M' | 'T' | 'N';
  status: ShiftStatus;
  system_total: number;
  real_total?: number;
  divergence?: number;
  closing?: CashClosing;
}

type ShiftStatus = 'open' | 'submitted' | 'approved' | 'rejected';

// Fechamento
interface CashClosing {
  id: number;
  lines: CashClosingLine[];
  submitted_at?: string;
  approved_at?: string;
  reviewer_id?: number;
}

interface CashClosingLine {
  payment_method: PaymentMethod;
  system_amount: number;
  real_amount: number;
  divergence: number;
  justification?: string;
}

// Bônus
interface SellerBonus {
  date: string;
  sales_total: number;
  bonus_amount: number;
  status: 'provisional' | 'confirmed' | 'zeroed';
  eligible: boolean;
}

// Ranking
interface RankingEntry {
  position: number;
  seller: {
    id: number;
    name: string;
    avatar_url?: string;
    store_name: string;
  };
  total_sold: number;
  goal: number;
  achievement_rate: number;
  bonus_accumulated: number;
}
```

---

## 📝 Exemplo de Integração

```typescript
// api.ts
import axios from 'axios';

const api = axios.create({
  baseURL: 'https://api.maiscapinhas.com.br/api/v1',
  headers: { 'Accept': 'application/json' }
});

api.interceptors.request.use(config => {
  const token = localStorage.getItem('token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// Login
export async function login(email: string, password: string) {
  const { data } = await api.post('/auth/login', { email, password });
  localStorage.setItem('token', data.data.token);
  return data.data.user;
}

// Dashboard
export async function getSellerDashboard() {
  const { data } = await api.get('/dashboard/seller');
  return data.data;
}

// Ranking
export async function getRanking(month?: string) {
  const { data } = await api.get('/reports/ranking', { params: { month } });
  return data.data;
}
```

---

**Última atualização:** 2026-01-08
