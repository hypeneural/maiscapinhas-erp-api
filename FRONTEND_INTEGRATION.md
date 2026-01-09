# 🔌 Respostas de Integração Frontend-Backend - MaisCapinhas ERP

**Data**: 2026-01-08  
**Baseado na análise da API Backend v1**

---

## ✅ Respostas às Perguntas do Frontend

### 1. Autenticação

#### O token atual é JWT ou Sanctum? Qual o tempo de expiração?

**Resposta:** A API usa **Laravel Sanctum** com tokens de API (não JWT).

- **Tipo**: Bearer Token (`1|abc123xyz...`)
- **Expiração**: Os tokens **não expiram automaticamente**
- **Revogação**: Via `POST /auth/logout` (token atual) ou `POST /auth/logout-all` (todos os tokens)

```typescript
// Header de autenticação
Authorization: Bearer 1|your-token-here
```

#### Existe refresh token implementado?

**Resposta:** ❌ **NÃO existe refresh token** implementado no backend.

Como os tokens Sanctum não expiram automaticamente, não há necessidade de refresh token. Se o frontend quiser implementar renovação, pode fazer logout e login novamente.

**Sugestão para o Frontend:**
```typescript
// Não precisa de /auth/refresh
// Em caso de 401, redirecionar para login
api.interceptors.response.use(
  response => response,
  error => {
    if (error.response?.status === 401) {
      localStorage.removeItem('token');
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);
```

#### O `GET /me` retorna o array `stores[]` com a role de cada loja?

**Resposta:** ✅ **SIM**, o endpoint `/me` retorna as lojas com roles.

```json
{
  "data": {
    "user": {
      "id": 1,
      "name": "Admin Sistema",
      "email": "admin@maiscapinhas.com.br",
      "active": true,
      "created_at": "2026-01-01T00:00:00+00:00"
    },
    "stores": [
      { "id": 1, "name": "Mais Capinhas Tijucas", "city": "Tijucas", "role": "admin" },
      { "id": 2, "name": "Mais Capinhas Itapema", "city": "Itapema", "role": "gerente" }
    ]
  }
}
```

**TypeScript Schema:**
```typescript
interface MeResponse {
  data: {
    user: {
      id: number;
      name: string;
      email: string;
      active: boolean;
      created_at: string; // ISO8601
    };
    stores: Array<{
      id: number;
      name: string;
      city: string;
      role: 'admin' | 'gerente' | 'conferente' | 'vendedor';
    }>;
  };
}
```

---

### 2. Multi-Loja (Store Context)

#### Um usuário pode ter roles **diferentes** em lojas diferentes?

**Resposta:** ✅ **SIM**, absolutamente!

O sistema usa uma tabela `store_users` que vincula usuários a lojas com roles específicos. Um mesmo usuário pode ser:
- `admin` na Loja Tijucas
- `gerente` na Loja Itapema  
- `vendedor` na Loja Bombinhas

```typescript
// Exemplo de stores retornadas no /me
stores: [
  { id: 1, name: "Tijucas", city: "Tijucas", role: "admin" },
  { id: 2, name: "Itapema", city: "Itapema", role: "gerente" },
  { id: 3, name: "Bombinhas", city: "Bombinhas", role: "vendedor" }
]
```

#### Os endpoints filtram automaticamente pela loja ou precisa enviar `store_id`?

**Resposta:** Depende do endpoint:

| Endpoint | Filtro Automático | Requer `store_id` |
|----------|-------------------|-------------------|
| `GET /me` | ✅ Retorna todas as lojas do usuário | ❌ |
| `GET /stores` | ✅ Só lojas com acesso | ❌ |
| `GET /dashboard/vendedor` | ❌ | ✅ **Obrigatório** |
| `GET /dashboard/conferente` | ❌ | ✅ **Obrigatório** |
| `GET /dashboard/admin` | ✅ Todas as lojas admin/gerente | ❌ |
| `GET /cash/shifts` | ✅ Todas do usuário | ❔ Opcional |
| `GET /sales` | ✅ Todas do usuário | ❔ Opcional |

**Regra Geral:** 
- Dashboards de vendedor/conferente **requerem** `store_id`
- Listagens (sales, shifts) filtram pelas lojas do usuário automaticamente, mas aceitam `store_id` para filtrar

#### Como enviar a loja selecionada nas requisições?

**Resposta:** Via **query parameter `store_id`** (não via header).

```typescript
// ✅ CORRETO - Query Parameter
GET /dashboard/vendedor?store_id=1

// ❌ NÃO IMPLEMENTADO - Header
// X-Store-Id: 1  (não é suportado atualmente)
```

**Sugestão de implementação no Frontend:**
```typescript
// Em api.ts
const api = axios.create({ baseURL: '/api/v1' });

// Adicionar store_id automaticamente
api.interceptors.request.use(config => {
  const storeId = sessionStorage.getItem('currentStoreId');
  if (storeId && config.method === 'get') {
    config.params = { ...config.params, store_id: storeId };
  }
  return config;
});
```

---

### 3. Dashboard

#### Existe um único endpoint `/dashboard` ou são separados?

**Resposta:** São **endpoints separados** por role:

| Role | Endpoint | Query Params |
|------|----------|--------------|
| Vendedor | `GET /dashboard/vendedor` | `store_id` (obrigatório), `date` (opcional) |
| Conferente | `GET /dashboard/conferente` | `store_id` (obrigatório), `date` (opcional) |
| Admin/Gerente | `GET /dashboard/admin` | `month` (opcional, formato: YYYY-MM) |

**Não existe** um endpoint único `/dashboard` que retorna dados diferentes por role.

#### O dashboard já calcula o `gamification.message`?

**Resposta:** ✅ **SIM**, o backend calcula e retorna a mensagem pronta!

```json
// Response de GET /dashboard/vendedor
{
  "bonus_gamification": {
    "current_amount": 450.00,
    "next_bonus_goal": 500.00,
    "gap_to_bonus": 50.00,
    "next_bonus_value": 10.00,
    "current_bonus_earned": 0,
    "message": "Faltam R$ 50,00 para ganhar R$ 10,00 de bônus!"
  }
}
```

**Schemas dos Dashboards:**

```typescript
// Dashboard Vendedor
interface SellerDashboard {
  date: string;
  my_sales: { count: number; total: number };
  store_sales: { count: number; total: number };
  bonus_gamification: {
    current_amount: number;
    next_bonus_goal: number;
    gap_to_bonus: number;
    next_bonus_value: number;
    current_bonus_earned: number;
    message: string;
  };
  monthly_commission: {
    sales_mtd: number;
    goal_amount: number;
    achievement_rate: number;
    current_tier: number;
    current_commission_value: number;
    next_tier: number;
    potential_commission: number;
  };
  daily_pace: {
    today_sales: number;
    average_daily_sales: number;
    today_vs_average: number;
    status: 'AHEAD' | 'BEHIND' | 'ON_TRACK';
  };
  my_shifts: CashShift[];
}

// Dashboard Conferente
interface ConferenteDashboard {
  date: string;
  pending_closings: CashClosing[];
  pending_count: number;
  store_sales: { count: number; total: number };
  shifts_today: Record<string, number>; // { open: 2, closed: 4 }
  top_sellers: Array<{ seller_id: number; name: string; total: number }>;
}

// Dashboard Admin
interface AdminDashboard {
  month: string;
  total_sales: { count: number; total: number };
  sales_by_store: Array<{
    store_id: number;
    store_name: string;
    count: number;
    total: number;
  }>;
  closings_summary: Record<string, number>; // { approved: 40, submitted: 5 }
  top_sellers: Array<{
    seller_id: number;
    name: string;
    total: number;
    count: number;
  }>;
}
```

---

### 4. Turnos e Fechamentos

#### O turno é criado automaticamente ou manualmente?

**Resposta:** O turno é criado **manualmente** via `POST /cash/shifts`.

```typescript
// Criar turno
POST /cash/shifts
{
  "store_id": 1,           // obrigatório
  "date": "2026-01-08",    // obrigatório
  "shift_code": "M",       // obrigatório: M (manhã), T (tarde), N (noite)
  "seller_id": 6           // opcional, default: usuário atual
}
```

**Regras:**
- Não pode haver dois turnos com mesma loja + data + turno + vendedor
- Turno inicia com status `open`

#### Qual a estrutura do fechamento?

**Resposta:** O fechamento é **por método de pagamento**, com linhas detalhadas:

```typescript
// Submeter fechamento
POST /cash/closings/{shift_id}/submit
{
  "lines": [
    {
      "payment_method": "dinheiro",
      "system_amount": 500.00,    // valor que o sistema registrou
      "real_amount": 480.00,      // valor real contado
      "justification": "Cliente pago a menos, vai acertar amanhã"  // obrigatório se divergência
    },
    {
      "payment_method": "pix",
      "system_amount": 1200.00,
      "real_amount": 1200.00
    }
  ]
}
```

**Métodos de pagamento aceitos:** `dinheiro`, `pix`, `credito`, `debito`

#### O endpoint `POST /closings/:id/submit` cria ou atualiza?

**Resposta:** Esse endpoint **cria E atualiza** (upsert behavior).
- Se o fechamento não existe, cria
- Se já existe em status `draft`, atualiza

**Endpoints de ações:**
- `POST /cash/closings/{shift}/submit` → Cria/atualiza e submete para aprovação
- `POST /cash/closings/{shift}/approve` → Aprova (gerente/conferente)
- `POST /cash/closings/{shift}/reject` → Rejeita com motivo

---

### 5. Bônus e Comissões

#### O cálculo de bônus é feito no backend ou frontend?

**Resposta:** ✅ **O backend calcula tudo!**

O frontend não precisa calcular nada. Use os endpoints:
- `GET /finance/bonus/calculate?amount=1200` → Simula bônus para um valor
- `GET /dashboard/vendedor?store_id=1` → Retorna gamificação completa

#### A tabela de bônus pode variar por loja?

**Resposta:** ✅ **SIM**, as regras podem ser:
- **Globais** (`store_id: null`) - aplicam a todas as lojas
- **Por loja** (`store_id: 1`) - específicas para uma loja

```json
// GET /rules/bonus retorna:
{
  "data": [
    {
      "id": 1,
      "store_id": null,           // Global
      "name": "Tabela Padrão",
      "config_json": [
        { "min_sales": 500, "bonus": 10 },
        { "min_sales": 1000, "bonus": 25 }
      ],
      "active": true
    },
    {
      "id": 2,
      "store_id": 1,              // Específica loja Tijucas
      "name": "Tabela Premium Tijucas",
      "config_json": [...],
      "active": true
    }
  ]
}
```

#### Estrutura de `/finance/bonus`

```typescript
// GET /finance/bonus?from=2026-01-01&to=2026-01-31
interface BonusResponse {
  data: Array<{
    date: string;
    sales_total: number;
    bonus_amount: number;
    status: 'provisional' | 'confirmed' | 'zeroed';
    eligible: boolean;
  }>;
}
```

---

### 6. Relatórios

#### O ranking retorna posição absoluta ou relativa à loja?

**Resposta:** O ranking é **multi-lojas** (todas as lojas que o usuário tem acesso):

```typescript
// GET /reports/ranking?month=2026-01
interface RankingResponse {
  data: {
    period: string;
    scope: "multi-store" | "single-store";
    podium: RankingEntry[];    // Top 3
    ranking: RankingEntry[];   // Todos
    stats: {
      total_sellers: number;
      above_goal: number;
      average_achievement: number;
    };
  };
}

interface RankingEntry {
  position: number;  // Posição absoluta global
  seller: {
    id: number;
    name: string;
    avatar_url?: string;
    store_name: string;  // Indica de qual loja é
  };
  total_sold: number;
  goal: number;
  achievement_rate: number;
  bonus_accumulated: number;
}
```

#### Os relatórios suportam filtro por período?

**Resposta:** ✅ **SIM**, use os parâmetros `from` e `to` ou `month`:

```typescript
// Período específico
GET /reports/store-performance?from=2026-01-01&to=2026-01-31&store_id=1

// Mês específico
GET /reports/ranking?month=2026-01
GET /dashboard/admin?month=2026-01
```

#### Existe endpoint de exportação (PDF/Excel)?

**Resposta:** ❌ **NÃO implementado atualmente.**

Recomendação: Implementar exportação no frontend usando bibliotecas como:
- **Excel**: `xlsx` ou `sheetjs`
- **PDF**: `jspdf` + `jspdf-autotable`

---

### 7. Usuários e Lojas (Admin)

#### O admin pode criar usuários em qualquer loja?

**Resposta:** ✅ **SIM**, o fluxo é:

1. Criar usuário: `POST /admin/users`
2. Vincular à loja: `POST /admin/stores/{store_id}/users`

```typescript
// 1. Criar usuário
POST /admin/users
{
  "name": "Novo Vendedor",
  "email": "vendedor@email.com",
  "password": "senha123",
  "birth_date": "1990-05-15",
  "whatsapp": "47999999999"
}

// 2. Vincular à loja com role
POST /admin/stores/1/users
{
  "user_id": 10,
  "role": "vendedor"
}
```

#### A role é global ou por loja?

**Resposta:** A role é **POR LOJA**. 

Um usuário pode ter roles diferentes em lojas diferentes. Isso é gerenciado pela tabela `store_users`.

#### Existe upload de avatar? Qual formato aceito?

**Resposta:** ✅ **SIM**, via `PUT /users/{id}/avatar`

```typescript
// Upload de avatar (multipart/form-data)
PUT /users/5/avatar
Content-Type: multipart/form-data

avatar: [arquivo]

// Formatos aceitos: jpg, png, webp
// Tamanho máximo: 2MB
// Dimensão mínima: 200x200 pixels
```

---

## 📊 Schemas TypeScript Atualizados

```typescript
// ============ AUTH ============
interface LoginRequest {
  email: string;
  password: string;
  device_name?: string;
}

interface LoginResponse {
  data: {
    token: string;
    token_type: 'Bearer';
    user: {
      id: number;
      name: string;
      email: string;
    };
  };
  meta: { request_id: string; timestamp: string };
}

// ============ USER ============
interface User {
  id: number;
  name: string;
  email: string;
  phone?: string;
  avatar_url?: string;
  birth_date?: string;
  active: boolean;
}

interface UserStore {
  id: number;
  name: string;
  city: string;
  role: 'admin' | 'gerente' | 'conferente' | 'vendedor';
}

// ============ CASH SHIFT ============
interface CashShift {
  id: number;
  store_id: number;
  seller_id: number;
  date: string;
  shift_code: 'M' | 'T' | 'N';
  status: 'open' | 'closed' | 'pending';
  store?: { id: number; name: string };
  seller?: { id: number; name: string };
  cash_closing?: CashClosing;
}

interface CashClosing {
  id: number;
  cash_shift_id: number;
  status: 'draft' | 'submitted' | 'approved' | 'rejected';
  lines: CashClosingLine[];
  submitted_at?: string;
  approved_at?: string;
  reviewer_id?: number;
}

interface CashClosingLine {
  payment_method: PaymentMethod;
  system_amount: number;
  real_amount: number;
  diff_value: number;
  justification_text?: string;
}

type PaymentMethod = 'dinheiro' | 'pix' | 'credito' | 'debito';

// ============ SALE ============
interface Sale {
  id: number;
  store_id: number;
  seller_id: number;
  amount: number;
  payment_method: PaymentMethod;
  sold_at: string;
  created_at: string;
  seller?: { id: number; name: string };
  store?: { id: number; name: string };
}

// ============ BONUS ============
interface BonusRule {
  id: number;
  store_id: number | null;
  name: string;
  config_json: Array<{ min_sales: number; bonus: number }>;
  valid_from: string;
  valid_to: string | null;
  active: boolean;
}

// ============ API RESPONSE ============
interface ApiResponse<T> {
  data: T;
  meta: {
    request_id: string;
    timestamp: string;
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

interface ErrorResponse {
  message: string;
  errors?: Record<string, string[]>;
}
```

---

## 🔧 Sugestões de Melhorias para o Frontend

### 1. Interceptor de Autenticação Recomendado

```typescript
// src/lib/api.ts
import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api/v1',
  headers: { 'Accept': 'application/json' }
});

// Adiciona token automaticamente
api.interceptors.request.use(config => {
  const token = localStorage.getItem('token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Trata erros globalmente
api.interceptors.response.use(
  response => response,
  error => {
    if (error.response?.status === 401) {
      localStorage.removeItem('token');
      sessionStorage.removeItem('currentStoreId');
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);

export default api;
```

### 2. Hook de Store Context

```typescript
// src/hooks/useStoreContext.ts
import { useCallback, useMemo } from 'react';
import { useAuth } from '@/contexts/AuthContext';

export function useStoreContext() {
  const { user } = useAuth();
  
  const currentStoreId = sessionStorage.getItem('currentStoreId');
  
  const currentStore = useMemo(() => {
    if (!currentStoreId || !user?.stores) return null;
    return user.stores.find(s => s.id === Number(currentStoreId));
  }, [currentStoreId, user?.stores]);
  
  const currentRole = currentStore?.role || 'vendedor';
  
  const setCurrentStore = useCallback((storeId: number) => {
    sessionStorage.setItem('currentStoreId', String(storeId));
  }, []);
  
  return { currentStoreId, currentStore, currentRole, setCurrentStore };
}
```

### 3. Service de Dashboard com Tipagem

```typescript
// src/services/dashboard.ts
import api from '@/lib/api';

export async function getSellerDashboard(storeId: number, date?: string) {
  const params = { store_id: storeId, date };
  const { data } = await api.get<ApiResponse<SellerDashboard>>('/dashboard/vendedor', { params });
  return data.data;
}

export async function getConferenteDashboard(storeId: number, date?: string) {
  const params = { store_id: storeId, date };
  const { data } = await api.get<ApiResponse<ConferenteDashboard>>('/dashboard/conferente', { params });
  return data.data;
}

export async function getAdminDashboard(month?: string) {
  const params = { month };
  const { data } = await api.get<ApiResponse<AdminDashboard>>('/dashboard/admin', { params });
  return data.data;
}
```

---

## 📋 Checklist de Endpoints para o Frontend

| Funcionalidade | Endpoint Backend | Status |
|---------------|------------------|--------|
| Login | `POST /auth/login` | ✅ Pronto |
| Logout | `POST /auth/logout` | ✅ Pronto |
| Perfil do Usuário | `GET /me` | ✅ Pronto |
| ~~Refresh Token~~ | ~~`POST /auth/refresh`~~ | ❌ Não existe |
| Forgot Password | `POST /auth/forgot-password` | ✅ Pronto |
| Reset Password | `POST /auth/reset-password` | ✅ Pronto |
| Listar Lojas | `GET /stores` | ✅ Pronto |
| Dashboard Vendedor | `GET /dashboard/vendedor` | ✅ Pronto |
| Dashboard Conferente | `GET /dashboard/conferente` | ✅ Pronto |
| Dashboard Admin | `GET /dashboard/admin` | ✅ Pronto |
| Listar Turnos | `GET /cash/shifts` | ✅ Pronto |
| Turnos Pendentes | `GET /cash/shifts/pending` | ✅ Pronto |
| Submeter Fechamento | `POST /cash/closings/{shift}/submit` | ✅ Pronto |
| Aprovar Fechamento | `POST /cash/closings/{shift}/approve` | ✅ Pronto |
| Bônus do Usuário | `GET /finance/bonus` | ✅ Pronto |
| Comissões | `GET /finance/commission` | ✅ Pronto |
| Ranking | `GET /reports/ranking` | ✅ Pronto |
| Regras de Bônus | `GET /rules/bonus` | ✅ Pronto |
| Metas | `GET /goals/monthly` | ✅ Pronto |
| Admin: Listar Usuários | `GET /admin/users` | ✅ Pronto |
| Admin: Vincular Usuário | `POST /admin/stores/{store}/users` | ✅ Pronto |
| Export PDF/Excel | - | ❌ Não existe |

---

## ⚠️ Pontos de Atenção

1. **Não há refresh token** - tokens não expiram, apenas são revogados no logout
2. **store_id é query param** - não use header `X-Store-Id`
3. **Dashboards são separados** - use o endpoint correto por role
4. **Turnos são manuais** - o vendedor/conferente cria o turno
5. **Backend calcula tudo** - gamificação, bônus, comissão vem prontos

---

**Documento gerado em**: 2026-01-08  
**Próxima atualização**: Após implementação de novas features
