# API Comemorações - Documentação Frontend

Base URL: `/api/v1/celebrations`

---

## Endpoints

| Método | Rota | Descrição |
|--------|------|-----------|
| GET | `/celebrations` | Listagem com filtros (tabela) |
| GET | `/celebrations/month` | Comemorações do mês |
| GET | `/celebrations/upcoming` | Widget próximos |
| GET | `/celebrations/today` | Destaques de hoje |

---

## 1. GET /celebrations

Listagem paginada com filtros para tabela dinâmica.

### Query Params

| Param | Tipo | Default | Descrição |
|-------|------|---------|-----------|
| `store_id` | int | - | Filtrar por loja |
| `type` | string | - | `birthday` ou `work_anniversary` |
| `month` | int | - | Mês (1-12) |
| `status` | string | - | `today`, `this_week`, `this_month`, `upcoming` |
| `keyword` | string | - | Busca por nome |
| `sort` | string | `days_until` | Campo: `name`, `date`, `days_until`, `store_name`, `years` |
| `direction` | string | `asc` | `asc` ou `desc` |
| `per_page` | int | 25 | Máx: 100 |
| `page` | int | 1 | Página atual |

### Response

```typescript
interface CelebrationsResponse {
  data: Celebration[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
  filters: {
    types: FilterOption[];
    statuses: FilterOption[];
    stores: StoreOption[];
    months: MonthOption[];
  };
  summary: {
    total: number;
    today: number;
    this_week: number;
    birthdays: number;
    work_anniversaries: number;
  };
}

interface Celebration {
  id: string;                    // "42_birthday" ou "42_work"
  user_id: number;
  user_name: string;
  avatar_url: string | null;
  store_id: number | null;
  store_name: string;
  type: 'birthday' | 'work_anniversary';
  type_label: string;            // "Aniversário" ou "Aniversário de Empresa"
  original_date: string;         // "1990-01-20"
  next_date: string;             // "2026-01-20"
  day: number;                   // 20
  month: number;                 // 1
  days_until: number;            // 4
  is_today: boolean;
  is_this_week: boolean;
  is_this_month: boolean;
  status: 'today' | 'this_week' | 'this_month' | 'upcoming';
  status_label: string;          // "Hoje", "Amanhã", "Em 4 dias"
  years: number | null;          // Anos de empresa (null para birthday)
  years_label?: string;          // "3 anos"
}

interface FilterOption {
  value: string;
  label: string;
}

interface StoreOption {
  id: number;
  name: string;
}

interface MonthOption {
  value: number;
  label: string;                 // "Janeiro", "Fevereiro", etc.
}
```

### Exemplo de Request

```bash
GET /api/v1/celebrations?status=this_week&sort=name&per_page=10
```

### Exemplo de Response

```json
{
  "data": [
    {
      "id": "42_birthday",
      "user_id": 42,
      "user_name": "Maria Silva",
      "avatar_url": "https://...",
      "store_id": 1,
      "store_name": "Loja Centro",
      "type": "birthday",
      "type_label": "Aniversário",
      "original_date": "1990-01-20",
      "next_date": "2026-01-20",
      "day": 20,
      "month": 1,
      "days_until": 4,
      "is_today": false,
      "is_this_week": true,
      "is_this_month": true,
      "status": "this_week",
      "status_label": "Em 4 dias",
      "years": null
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 10,
    "total": 15,
    "last_page": 2
  },
  "filters": {
    "types": [
      {"value": "birthday", "label": "Aniversário"},
      {"value": "work_anniversary", "label": "Aniversário de Empresa"}
    ],
    "statuses": [
      {"value": "today", "label": "Hoje"},
      {"value": "this_week", "label": "Esta Semana"},
      {"value": "this_month", "label": "Este Mês"},
      {"value": "upcoming", "label": "Próximos"}
    ],
    "stores": [
      {"id": 1, "name": "Loja Centro"},
      {"id": 2, "name": "Loja Shopping"}
    ],
    "months": [
      {"value": 1, "label": "janeiro"},
      {"value": 2, "label": "fevereiro"}
    ]
  },
  "summary": {
    "total": 15,
    "today": 2,
    "this_week": 5,
    "birthdays": 10,
    "work_anniversaries": 5
  }
}
```

---

## 2. GET /celebrations/month

Comemorações de um mês específico.

### Query Params

| Param | Tipo | Default | Descrição |
|-------|------|---------|-----------|
| `month` | int | atual | Mês (1-12) |
| `year` | int | atual | Ano |
| `store_id` | int | - | Filtrar por loja |
| `type` | string | - | `birthday` ou `work_anniversary` |

### Response

```typescript
interface MonthResponse {
  data: {
    month: number;
    year: number;
    celebrations: MonthCelebration[];
    summary: {
      total: number;
      birthdays: number;
      work_anniversaries: number;
      today: number;
      upcoming_this_week: number;
    };
  };
}

interface MonthCelebration {
  id: string;
  user_id: number;
  user_name: string;
  avatar_url: string | null;
  store_id: number | null;
  store_name: string;
  type: 'birthday' | 'work_anniversary';
  date: string;
  day_of_month: number;
  days_until: number;
  is_today: boolean;
  is_past: boolean;
  years: number | null;
}
```

---

## 3. GET /celebrations/upcoming

Próximas comemorações para widget do dashboard.

### Query Params

| Param | Tipo | Default | Descrição |
|-------|------|---------|-----------|
| `limit` | int | 5 | Quantidade |
| `days` | int | 7 | Próximos X dias |
| `store_id` | int | - | Filtrar por loja |

### Response

```typescript
interface UpcomingResponse {
  data: UpcomingCelebration[];
}

interface UpcomingCelebration {
  user_id: number;
  user_name: string;
  avatar_url: string | null;
  store_name: string;
  type: 'birthday' | 'work_anniversary';
  date: string;
  days_until: number;
  years?: number;  // Apenas para work_anniversary
}
```

### Exemplo

```json
{
  "data": [
    {
      "user_id": 42,
      "user_name": "Maria Silva",
      "avatar_url": null,
      "store_name": "Loja Centro",
      "type": "birthday",
      "date": "2026-01-20",
      "days_until": 4
    }
  ]
}
```

---

## 4. GET /celebrations/today

Comemorações de hoje com mensagem para destaque.

### Response

```typescript
interface TodayResponse {
  data: TodayCelebration[];
}

interface TodayCelebration {
  user_id: number;
  user_name: string;
  avatar_url: string | null;
  store_name: string;
  type: 'birthday' | 'work_anniversary';
  years: number | null;
  message: string;  // "🎂 Hoje é aniversário de Maria!"
}
```

### Exemplo

```json
{
  "data": [
    {
      "user_id": 7,
      "user_name": "João Souza",
      "avatar_url": null,
      "store_name": "Loja Shopping",
      "type": "work_anniversary",
      "years": 3,
      "message": "🎉 João Souza completou 3 anos na Mais Capinhas!"
    }
  ]
}
```

---

## Permissão

- **Required**: `celebrations.view`

---

## React Query Hooks (sugestão)

```typescript
// hooks/use-celebrations.ts
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

interface CelebrationsParams {
  storeId?: number;
  type?: 'birthday' | 'work_anniversary';
  month?: number;
  status?: 'today' | 'this_week' | 'this_month' | 'upcoming';
  keyword?: string;
  sort?: string;
  direction?: 'asc' | 'desc';
  perPage?: number;
  page?: number;
}

export function useCelebrations(params: CelebrationsParams = {}) {
  return useQuery({
    queryKey: ['celebrations', params],
    queryFn: () => api.get('/celebrations', { params }).then(r => r.data),
  });
}

export function useCelebrationsToday() {
  return useQuery({
    queryKey: ['celebrations', 'today'],
    queryFn: () => api.get('/celebrations/today').then(r => r.data),
  });
}

export function useCelebrationsUpcoming(limit = 5, days = 7) {
  return useQuery({
    queryKey: ['celebrations', 'upcoming', limit, days],
    queryFn: () => api.get('/celebrations/upcoming', { 
      params: { limit, days } 
    }).then(r => r.data),
  });
}
```
