# 📋 API de Logs de Auditoria

> **Guia Completo para Frontend** | Última atualização: 2026-01-09

Este documento detalha o consumo dos endpoints de auditoria do sistema, permitindo que administradores visualizem todas as ações registradas para compliance e segurança.

---

## 📌 Índice

1. [Visão Geral](#visão-geral)
2. [Autenticação](#autenticação)
3. [Endpoints](#endpoints)
   - [Listar Logs](#1-listar-logs)
   - [Ver Detalhes](#2-ver-detalhes-do-log)
   - [Estatísticas](#3-estatísticas)
4. [Schemas TypeScript](#schemas-typescript)
5. [Filtros Disponíveis](#filtros-disponíveis)
6. [Tipos de Eventos](#tipos-de-eventos)
7. [Exemplos de Uso](#exemplos-de-uso)
8. [Componentes Sugeridos](#componentes-sugeridos)

---

## Visão Geral

O sistema de auditoria registra automaticamente todas as ações críticas:

| Domínio | Descrição | Exemplos de Eventos |
|---------|-----------|---------------------|
| `auth` | Autenticação | `auth.login`, `auth.logout`, `auth.login_failed` |
| `cash` | Conferência de Caixa | `cash_closing.submit`, `cash_closing.approve`, `cash_closing.reject` |
| `rules` | Regras de Bonificação/Comissão | `bonus_rule.created`, `commission_rule.updated` |
| `goals` | Metas Mensais | `goal.created`, `goal.updated`, `goal.splits_updated` |
| `sales` | Vendas | `sale.created`, `sale.updated`, `sale.deleted` |
| `admin` | Administração | `user.created`, `store.updated`, `user.role_changed` |

> [!IMPORTANT]
> **Apenas administradores** podem acessar os endpoints de auditoria.

---

## Autenticação

Todos os endpoints requerem token Bearer:

```http
Authorization: Bearer {token}
```

---

## Endpoints

### 1. Listar Logs

```http
GET /api/v1/admin/audit-logs
```

#### Query Parameters

| Parâmetro | Tipo | Obrigatório | Descrição |
|-----------|------|-------------|-----------|
| `from` | `string` | Não | Data inicial (YYYY-MM-DD) |
| `to` | `string` | Não | Data final (YYYY-MM-DD) |
| `causer_id` | `integer` | Não | ID do usuário que executou a ação |
| `event` | `string` | Não | Nome do evento (suporta wildcard: `auth.*`) |
| `log_name` | `string` | Não | Domínio: `auth`, `cash`, `rules`, `goals`, `sales`, `admin` |
| `store_id` | `integer` | Não | ID da loja relacionada |
| `subject_type` | `string` | Não | Tipo de entidade: `User`, `CashClosing`, `BonusRule`, etc |
| `subject_id` | `integer` | Não | ID da entidade (requer `subject_type`) |
| `per_page` | `integer` | Não | Itens por página (1-100, default: 25) |

#### Response (200 OK)

```json
{
  "data": [
    {
      "id": 150,
      "event": "cash_closing.submit",
      "action": "submit",
      "log_name": "cash",
      "created_at": "2026-01-07T18:30:00+00:00",
      "causer": {
        "id": 6,
        "name": "João Vendedor",
        "email": "joao@maiscapinhas.com"
      },
      "subject": {
        "type": "CashClosing",
        "id": 45
      },
      "store": {
        "id": 1,
        "name": "Mais Capinhas Tijucas"
      },
      "context": {
        "request_id": "abc-123-def-456",
        "ip": "192.168.1.100",
        "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36..."
      },
      "properties": {
        "status_from": "draft",
        "status_to": "submitted",
        "divergence_total": 0.00
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 150,
    "last_page": 6
  }
}
```

---

### 2. Ver Detalhes do Log

```http
GET /api/v1/admin/audit-logs/{id}
```

#### Response (200 OK)

```json
{
  "data": {
    "id": 150,
    "event": "bonus_rule.updated",
    "action": "update",
    "log_name": "rules",
    "created_at": "2026-01-07T15:45:00+00:00",
    "causer": {
      "id": 1,
      "name": "Admin Master",
      "email": "admin@maiscapinhas.com"
    },
    "subject": {
      "type": "BonusRule",
      "id": 5
    },
    "store": {
      "id": 2,
      "name": "Mais Capinhas Balneário"
    },
    "context": {
      "request_id": "req-789-xyz",
      "ip": "192.168.1.50",
      "user_agent": "Mozilla/5.0..."
    },
    "properties": {
      "name": "Bônus Atualizado",
      "old_value": 100.00,
      "new_value": 150.00
    }
  }
}
```

---

### 3. Estatísticas

```http
GET /api/v1/admin/audit-logs/stats
```

#### Query Parameters

| Parâmetro | Tipo | Obrigatório | Descrição |
|-----------|------|-------------|-----------|
| `from` | `string` | Não | Data inicial (YYYY-MM-DD) |
| `to` | `string` | Não | Data final (YYYY-MM-DD) |

#### Response (200 OK)

```json
{
  "data": {
    "total_logs": 1500,
    "by_log_name": {
      "auth": 500,
      "cash": 800,
      "rules": 120,
      "goals": 50,
      "admin": 30
    },
    "by_action": {
      "login": 450,
      "submit": 350,
      "approve": 250,
      "create": 200,
      "update": 150,
      "reject": 50,
      "delete": 30,
      "logout": 20
    },
    "unique_users": 15,
    "period": {
      "from": "2026-01-01",
      "to": "2026-01-31"
    }
  }
}
```

---

## Schemas TypeScript

```typescript
// ============================================
// Types para Logs de Auditoria
// ============================================

/** Domínios de log disponíveis */
type AuditLogName = 'auth' | 'cash' | 'rules' | 'goals' | 'sales' | 'admin' | 'analytics';

/** Ações comuns do sistema */
type AuditAction = 
  | 'login' 
  | 'logout' 
  | 'login_failed'
  | 'create' 
  | 'update' 
  | 'delete'
  | 'submit' 
  | 'approve' 
  | 'reject';

/** Tipos de entidades rastreadas */
type SubjectType = 
  | 'User' 
  | 'Store' 
  | 'CashClosing' 
  | 'CashShift'
  | 'BonusRule' 
  | 'CommissionRule' 
  | 'MonthlyGoal'
  | 'Sale';

/** Usuário resumido (causer) */
interface AuditCauser {
  id: number;
  name: string;
  email: string;
}

/** Entidade afetada */
interface AuditSubject {
  type: SubjectType;
  id: number;
}

/** Loja relacionada */
interface AuditStore {
  id: number;
  name: string;
}

/** Contexto da requisição */
interface AuditContext {
  request_id: string | null;
  ip: string | null;
  user_agent: string | null;
}

/** Propriedades do log (dados antes/depois) */
interface AuditProperties {
  [key: string]: unknown;
  _truncated?: boolean;
  _size?: number;
  _message?: string;
}

/** Log de auditoria individual */
interface AuditLog {
  id: number;
  event: string;
  action: AuditAction;
  log_name: AuditLogName;
  created_at: string; // ISO 8601
  causer: AuditCauser | null;
  subject: AuditSubject | null;
  store: AuditStore | null;
  context: AuditContext;
  properties: AuditProperties | null;
}

/** Resposta paginada de logs */
interface AuditLogsResponse {
  data: AuditLog[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
}

/** Estatísticas de logs */
interface AuditStats {
  total_logs: number;
  by_log_name: Record<AuditLogName, number>;
  by_action: Record<string, number>;
  unique_users: number;
  period: {
    from: string | null;
    to: string | null;
  };
}

/** Parâmetros de filtro */
interface AuditLogFilters {
  from?: string;
  to?: string;
  causer_id?: number;
  event?: string;
  log_name?: AuditLogName;
  store_id?: number;
  subject_type?: SubjectType;
  subject_id?: number;
  per_page?: number;
  page?: number;
}
```

---

## Filtros Disponíveis

### Filtro por Usuário (Ator)
```http
GET /api/v1/admin/audit-logs?causer_id=5
```
> Mostra todas as ações executadas pelo usuário ID 5.

### Filtro por Loja
```http
GET /api/v1/admin/audit-logs?store_id=1
```
> Mostra apenas ações relacionadas à loja ID 1.

### Filtro por Período
```http
GET /api/v1/admin/audit-logs?from=2026-01-01&to=2026-01-31
```
> Mostra ações no período especificado (inclusive).

### Filtro por Domínio
```http
GET /api/v1/admin/audit-logs?log_name=auth
```
> Mostra apenas ações de autenticação.

### Filtro por Evento (com Wildcard)
```http
# Evento específico
GET /api/v1/admin/audit-logs?event=cash_closing.submit

# Wildcard (todos eventos de cash_closing)
GET /api/v1/admin/audit-logs?event=cash_closing.*
```

### Filtro por Entidade
```http
GET /api/v1/admin/audit-logs?subject_type=CashClosing&subject_id=45
```
> Mostra histórico completo de uma entidade específica.

### Combinando Filtros
```http
GET /api/v1/admin/audit-logs?causer_id=5&log_name=cash&from=2026-01-01&to=2026-01-07
```
> Ações do usuário 5, no domínio cash, na primeira semana de janeiro.

---

## Tipos de Eventos

### Autenticação (`auth`)

| Evento | Descrição | Properties Típicas |
|--------|-----------|-------------------|
| `auth.login` | Login bem-sucedido | `{ auth_mode, device_name }` |
| `auth.logout` | Logout | `{ token_revoked }` |
| `auth.login_failed` | Tentativa falha | `{ reason, email }` |
| `auth.password_changed` | Senha alterada | `{ forced }` |
| `auth.password_reset` | Reset de senha | `{ email }` |

### Conferência de Caixa (`cash`)

| Evento | Descrição | Properties Típicas |
|--------|-----------|-------------------|
| `cash_closing.submit` | Caixa enviado | `{ status_from, status_to, divergence_total }` |
| `cash_closing.approve` | Caixa aprovado | `{ approved_by, notes }` |
| `cash_closing.reject` | Caixa rejeitado | `{ reason, rejected_by }` |

### Regras (`rules`)

| Evento | Descrição | Properties Típicas |
|--------|-----------|-------------------|
| `bonus_rule.created` | Regra de bônus criada | `{ name, type, value }` |
| `bonus_rule.updated` | Regra atualizada | `{ old, new }` |
| `bonus_rule.deleted` | Regra removida | `{ deleted_at }` |
| `commission_rule.created` | Regra de comissão | `{ name, percentage }` |
| `commission_rule.updated` | Comissão atualizada | `{ old_percentage, new_percentage }` |

### Metas (`goals`)

| Evento | Descrição | Properties Típicas |
|--------|-----------|-------------------|
| `goal.created` | Meta mensal criada | `{ year_month, target_value }` |
| `goal.updated` | Meta atualizada | `{ old_value, new_value }` |
| `goal.splits_updated` | Divisão atualizada | `{ splits }` |

### Administração (`admin`)

| Evento | Descrição | Properties Típicas |
|--------|-----------|-------------------|
| `user.created` | Usuário criado | `{ email, name, stores }` |
| `user.updated` | Usuário atualizado | `{ changed_fields }` |
| `user.role_changed` | Role alterada | `{ store_id, old_role, new_role }` |
| `store.created` | Loja criada | `{ name, code }` |
| `store.updated` | Loja atualizada | `{ changed_fields }` |

---

## Exemplos de Uso

### React Query Hook

```typescript
// hooks/useAuditLogs.ts
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { AuditLogsResponse, AuditLogFilters } from '@/types/audit';

export function useAuditLogs(filters: AuditLogFilters = {}) {
  return useQuery<AuditLogsResponse>({
    queryKey: ['audit-logs', filters],
    queryFn: async () => {
      const params = new URLSearchParams();
      
      Object.entries(filters).forEach(([key, value]) => {
        if (value !== undefined && value !== '') {
          params.append(key, String(value));
        }
      });
      
      const response = await api.get(`/admin/audit-logs?${params}`);
      return response.data;
    },
    staleTime: 30 * 1000, // 30 segundos (logs são dinâmicos)
  });
}

export function useAuditStats(from?: string, to?: string) {
  return useQuery({
    queryKey: ['audit-stats', from, to],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (from) params.append('from', from);
      if (to) params.append('to', to);
      
      const response = await api.get(`/admin/audit-logs/stats?${params}`);
      return response.data.data;
    },
    staleTime: 60 * 1000, // 1 minuto
  });
}

export function useAuditLog(id: number) {
  return useQuery({
    queryKey: ['audit-log', id],
    queryFn: async () => {
      const response = await api.get(`/admin/audit-logs/${id}`);
      return response.data.data;
    },
    enabled: !!id,
  });
}
```

### Componente de Filtros

```tsx
// components/AuditFilters.tsx
import { useState } from 'react';
import { DateRangePicker } from '@/components/ui/date-range-picker';
import { Select } from '@/components/ui/select';
import { Input } from '@/components/ui/input';

interface AuditFiltersProps {
  onFilterChange: (filters: AuditLogFilters) => void;
  stores: Array<{ id: number; name: string }>;
  users: Array<{ id: number; name: string }>;
}

const LOG_NAMES = [
  { value: '', label: 'Todos os domínios' },
  { value: 'auth', label: '🔐 Autenticação' },
  { value: 'cash', label: '💰 Conferência de Caixa' },
  { value: 'rules', label: '📋 Regras' },
  { value: 'goals', label: '🎯 Metas' },
  { value: 'sales', label: '🛒 Vendas' },
  { value: 'admin', label: '⚙️ Administração' },
];

export function AuditFilters({ onFilterChange, stores, users }: AuditFiltersProps) {
  const [filters, setFilters] = useState<AuditLogFilters>({});

  const handleChange = (key: keyof AuditLogFilters, value: unknown) => {
    const newFilters = { ...filters, [key]: value || undefined };
    setFilters(newFilters);
    onFilterChange(newFilters);
  };

  return (
    <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4 p-4 bg-card rounded-lg border">
      {/* Período */}
      <DateRangePicker
        onChange={(range) => {
          handleChange('from', range?.from);
          handleChange('to', range?.to);
        }}
        placeholder="Selecione o período"
      />

      {/* Domínio */}
      <Select
        options={LOG_NAMES}
        onChange={(value) => handleChange('log_name', value)}
        placeholder="Domínio"
      />

      {/* Usuário */}
      <Select
        options={[
          { value: '', label: 'Todos os usuários' },
          ...users.map(u => ({ value: u.id, label: u.name }))
        ]}
        onChange={(value) => handleChange('causer_id', value)}
        placeholder="Usuário"
      />

      {/* Loja */}
      <Select
        options={[
          { value: '', label: 'Todas as lojas' },
          ...stores.map(s => ({ value: s.id, label: s.name }))
        ]}
        onChange={(value) => handleChange('store_id', value)}
        placeholder="Loja"
      />

      {/* Evento (busca livre) */}
      <Input
        placeholder="Evento (ex: cash_closing.*)"
        onChange={(e) => handleChange('event', e.target.value)}
      />
    </div>
  );
}
```

### Tabela de Logs

```tsx
// components/AuditLogTable.tsx
import { formatDistanceToNow } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import type { AuditLog } from '@/types/audit';

const EVENT_ICONS: Record<string, string> = {
  'auth': '🔐',
  'cash': '💰',
  'rules': '📋',
  'goals': '🎯',
  'sales': '🛒',
  'admin': '⚙️',
};

const ACTION_BADGES: Record<string, { color: string; label: string }> = {
  'login': { color: 'bg-green-100 text-green-800', label: 'Login' },
  'logout': { color: 'bg-gray-100 text-gray-800', label: 'Logout' },
  'create': { color: 'bg-blue-100 text-blue-800', label: 'Criar' },
  'update': { color: 'bg-yellow-100 text-yellow-800', label: 'Atualizar' },
  'delete': { color: 'bg-red-100 text-red-800', label: 'Excluir' },
  'submit': { color: 'bg-purple-100 text-purple-800', label: 'Enviar' },
  'approve': { color: 'bg-green-100 text-green-800', label: 'Aprovar' },
  'reject': { color: 'bg-red-100 text-red-800', label: 'Rejeitar' },
};

interface Props {
  logs: AuditLog[];
  onViewDetails: (id: number) => void;
}

export function AuditLogTable({ logs, onViewDetails }: Props) {
  return (
    <table className="w-full">
      <thead>
        <tr className="border-b">
          <th className="text-left p-3">Evento</th>
          <th className="text-left p-3">Usuário</th>
          <th className="text-left p-3">Loja</th>
          <th className="text-left p-3">IP</th>
          <th className="text-left p-3">Data</th>
          <th className="text-left p-3">Ações</th>
        </tr>
      </thead>
      <tbody>
        {logs.map((log) => {
          const icon = EVENT_ICONS[log.log_name] || '📝';
          const badge = ACTION_BADGES[log.action] || { 
            color: 'bg-gray-100 text-gray-800', 
            label: log.action 
          };

          return (
            <tr key={log.id} className="border-b hover:bg-muted/50">
              <td className="p-3">
                <div className="flex items-center gap-2">
                  <span>{icon}</span>
                  <div>
                    <div className="font-medium">{log.event}</div>
                    <span className={`text-xs px-2 py-0.5 rounded ${badge.color}`}>
                      {badge.label}
                    </span>
                  </div>
                </div>
              </td>
              <td className="p-3">
                {log.causer ? (
                  <div>
                    <div className="font-medium">{log.causer.name}</div>
                    <div className="text-sm text-muted-foreground">
                      {log.causer.email}
                    </div>
                  </div>
                ) : (
                  <span className="text-muted-foreground">Sistema</span>
                )}
              </td>
              <td className="p-3">
                {log.store?.name || '-'}
              </td>
              <td className="p-3 text-sm font-mono">
                {log.context.ip || '-'}
              </td>
              <td className="p-3 text-sm">
                <div title={log.created_at}>
                  {formatDistanceToNow(new Date(log.created_at), {
                    addSuffix: true,
                    locale: ptBR
                  })}
                </div>
              </td>
              <td className="p-3">
                <button 
                  onClick={() => onViewDetails(log.id)}
                  className="text-primary hover:underline"
                >
                  Ver detalhes
                </button>
              </td>
            </tr>
          );
        })}
      </tbody>
    </table>
  );
}
```

---

## Componentes Sugeridos

### Página Principal de Auditoria

```
/configuracoes/auditoria
├── AuditStatsCards      → Cards com estatísticas do período
├── AuditFilters         → Filtros (período, usuário, loja, domínio)
├── AuditLogTable        → Tabela paginada de logs
└── AuditLogDetailModal  → Modal com detalhes completos
```

### Cards de Estatísticas

- **Total de Logs** no período
- **Gráfico de pizza** por domínio (`by_log_name`)
- **Top 5 ações** mais frequentes (`by_action`)
- **Usuários ativos** (`unique_users`)

### Modal de Detalhes

Ao clicar em "Ver detalhes":
- Mostrar todos os campos do log
- Exibir `properties` em JSON formatado (com syntax highlighting)
- Mostrar `before_json` e `after_json` lado a lado (diff visual)

---

## Erros Comuns

| Código | Mensagem | Causa |
|--------|----------|-------|
| `401` | Unauthenticated | Token inválido ou expirado |
| `403` | Apenas administradores podem acessar | Usuário não é admin |
| `404` | Not Found | Log ID não existe |
| `422` | Validation Error | Parâmetros inválidos (date format, etc) |

---

## Considerações de Performance

1. **Paginação**: Sempre use `per_page` (max 100) para evitar carregar muitos dados
2. **Filtros**: Use filtros específicos (período, store_id) para reduzir volume
3. **Cache**: Logs são imutáveis, pode usar cache mais longo para detalhes
4. **Stats**: Atualizar a cada 1 minuto é suficiente

---

> [!TIP]
> Para debugar ações de um usuário específico, use:
> ```
> GET /api/v1/admin/audit-logs?causer_id=X&per_page=50
> ```

> [!NOTE]
> Logs são criados automaticamente pelo backend via `AuditLogger`. 
> O frontend apenas **consulta** os dados, nunca cria ou modifica logs.
