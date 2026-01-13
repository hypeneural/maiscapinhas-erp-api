# Frontend Integration Guide - Internal Communication API

## Sumário

1. [Visão Geral](#visão-geral)
2. [Schemas e Tipos](#schemas-e-tipos)
3. [Endpoints](#endpoints)
4. [Permissões (RBAC)](#permissões-rbac)
5. [Fluxos de Tela](#fluxos-de-tela)
6. [Boas Práticas](#boas-práticas)
7. [Exemplos de Código](#exemplos-de-código)

---

## Visão Geral

O Sistema de Comunicação Interna permite que administradores e gerentes enviem comunicados para colaboradores. Os comunicados podem aparecer como:

- **Banner Carousel**: Carrossel rotativo no dashboard
- **Modal**: Pop-up que exige interação
- **Ambos**: Combinação dos dois

### Tipos de Comunicado
| Tipo | Descrição | Severidade Padrão |
|------|-----------|-------------------|
| `recado` | Comunicado informativo | `info` ou `warning` |
| `advertencia` | Aviso formal/advertência | `danger` (obrigatório) |

### Escopos de Visibilidade
| Escopo | Quem vê | Quem pode criar |
|--------|---------|-----------------|
| `global` | Todos os usuários | Apenas Admin |
| `store` | Usuários de lojas específicas | Admin ou Gerente |
| `user` | Usuários específicos | Apenas Admin |
| `role` | Usuários com cargo específico | Admin ou Gerente |

---

## Schemas e Tipos

### TypeScript Definitions

```typescript
// Enums
type AnnouncementType = 'recado' | 'advertencia';
type AnnouncementSeverity = 'info' | 'warning' | 'danger';
type AnnouncementScope = 'global' | 'store' | 'user' | 'role';
type AnnouncementStatus = 'draft' | 'scheduled' | 'active' | 'expired' | 'archived';
type AnnouncementDisplayMode = 'banner' | 'modal' | 'both';
type AnnouncementTargetType = 'store' | 'user' | 'role';

// Interfaces
interface AnnouncementTarget {
  target_type: AnnouncementTargetType;
  target_id: string;
}

interface AnnouncementReceipt {
  seen_at: string | null;
  acknowledged_at: string | null;
  dismissed_at: string | null;
  last_shown_at: string | null;
  show_count: number;
}

interface EnumValue<T = string> {
  value: T;
  label: string;
  color?: string;
}

interface AnnouncementSummary {
  id: number;
  title: string;
  excerpt: string;
  type: EnumValue<AnnouncementType>;
  severity: EnumValue<AnnouncementSeverity>;
  display_mode: EnumValue<AnnouncementDisplayMode>;
  require_ack: boolean;
  icon: string | null;
  image_url: string | null;
  image_alt: string | null;
  cta_label: string | null;
  cta_url: string | null;
  starts_at: string | null;
  expires_at: string | null;
  is_pinned: boolean;
  is_critical: boolean;
  receipt: AnnouncementReceipt | null;
}

interface AnnouncementDetail extends AnnouncementSummary {
  message: string;
  scope: EnumValue<AnnouncementScope>;
  status: EnumValue<AnnouncementStatus>;
  repeat_every_minutes: number | null;
  priority: number;
  pinned_until: string | null;
  meta_json: Record<string, any> | null;
  targets: AnnouncementTarget[];
  published_at: string | null;
  published_by: { id: number; name: string } | null;
  archived_at: string | null;
  archived_by: { id: number; name: string } | null;
  created_by: { id: number; name: string };
  created_at: string;
  updated_at: string;
}

interface ActiveAnnouncementsResponse {
  critical: AnnouncementSummary[];
  banners: AnnouncementSummary[];
}

interface CreateAnnouncementPayload {
  title: string;
  message: string;
  excerpt?: string;
  type: AnnouncementType;
  severity: AnnouncementSeverity;
  display_mode?: AnnouncementDisplayMode;
  icon?: string;
  image_url?: string;
  image_alt?: string;
  cta_label?: string;
  cta_url?: string;
  scope: AnnouncementScope;
  require_ack?: boolean;
  starts_at?: string; // ISO 8601
  expires_at?: string; // ISO 8601
  repeat_every_minutes?: number;
  priority?: number;
  pinned_until?: string;
  targets?: AnnouncementTarget[];
}

interface UpdateAnnouncementPayload extends Partial<CreateAnnouncementPayload> {}

// Filtros para listagem
interface AnnouncementFilters {
  status?: AnnouncementStatus | 'all';
  only_unacknowledged?: boolean;
  only_unseen?: boolean;
  severity?: AnnouncementSeverity;
  type?: AnnouncementType;
  scope?: AnnouncementScope;
  store_id?: number;
  created_by?: number;
  date_from?: string;
  date_to?: string;
  per_page?: number;
  page?: number;
  sort?: 'starts_at_desc' | 'starts_at_asc' | 'created_at_desc' | 'created_at_asc' | 'severity_desc' | 'priority_desc';
}
```

### Relacionamentos

```
┌─────────────────────────────────────────────────────────────────┐
│                        announcements                             │
├─────────────────────────────────────────────────────────────────┤
│ id                     │ PK                                      │
│ created_by_user_id     │ FK → users.id (quem criou)              │
│ published_by_user_id   │ FK → users.id (quem publicou)           │
│ archived_by_user_id    │ FK → users.id (quem arquivou)           │
└─────────────────────────────────────────────────────────────────┘
         │ 1
         │
         │ N
┌─────────────────────────────────────────────────────────────────┐
│                    announcement_targets                          │
├─────────────────────────────────────────────────────────────────┤
│ id                     │ PK                                      │
│ announcement_id        │ FK → announcements.id                   │
│ target_type            │ 'store' | 'user' | 'role'               │
│ target_id              │ ID da loja/usuário ou nome do cargo     │
└─────────────────────────────────────────────────────────────────┘

         │ 1
         │
         │ N
┌─────────────────────────────────────────────────────────────────┐
│                    announcement_receipts                         │
├─────────────────────────────────────────────────────────────────┤
│ id                     │ PK                                      │
│ announcement_id        │ FK → announcements.id                   │
│ user_id                │ FK → users.id                           │
│ store_id               │ FK → stores.id (contexto)               │
│ delivered_at           │ Quando foi entregue                     │
│ seen_at                │ Quando clicou "Ler agora"               │
│ acknowledged_at        │ Quando clicou "RECEBIDO"                │
│ dismissed_at           │ Quando dispensou                        │
│ last_shown_at          │ Última exibição                         │
│ show_count             │ Quantas vezes foi exibido               │
└─────────────────────────────────────────────────────────────────┘
```

---

## Endpoints

### 1. Dashboard - Avisos Ativos

**`GET /api/v1/me/announcements/active`**

Retorna avisos elegíveis para o usuário atual, separados em críticos e banners.

| Parâmetro | Tipo | Descrição |
|-----------|------|-----------|
| `store_id` | number (opcional) | Filtra por loja específica |

**Resposta:**
```json
{
  "data": {
    "critical": [
      {
        "id": 1,
        "title": "⚠️ Advertência",
        "excerpt": "Você precisa confirmar...",
        "type": { "value": "advertencia", "label": "Advertência" },
        "severity": { "value": "danger", "label": "Urgente", "color": "red" },
        "display_mode": { "value": "modal", "label": "Modal" },
        "require_ack": true,
        "is_critical": true,
        "receipt": {
          "seen_at": null,
          "acknowledged_at": null,
          "show_count": 1
        }
      }
    ],
    "banners": [
      {
        "id": 2,
        "title": "Novo Horário",
        "excerpt": "A partir de segunda...",
        "type": { "value": "recado", "label": "Recado" },
        "severity": { "value": "info", "label": "Informativo", "color": "blue" },
        "require_ack": false,
        "icon": "clock",
        "image_url": "https://...",
        "cta_label": "Ler agora",
        "receipt": null
      }
    ]
  },
  "meta": { "request_id": "...", "timestamp": "..." }
}
```

**Quando chamar:** No carregamento do dashboard, após login, e periodicamente (polling a cada 5min ou WebSocket).

---

### 2. Histórico do Usuário

**`GET /api/v1/me/announcements`**

Lista todos os avisos que o usuário já recebeu.

| Parâmetro | Tipo | Descrição |
|-----------|------|-----------|
| `status` | string | `active`, `expired`, `all` |
| `only_unacknowledged` | boolean | Apenas não confirmados |
| `only_unseen` | boolean | Apenas não lidos |
| `severity` | string | Filtrar por severidade |
| `type` | string | Filtrar por tipo |
| `per_page` | number | Itens por página (default: 15) |
| `page` | number | Página atual |
| `sort` | string | Ordenação |

**Resposta:** Paginada com `meta.pagination`.

---

### 3. Detalhes do Aviso

**`GET /api/v1/announcements/{id}`**

Retorna todos os detalhes de um aviso específico.

---

### 4. Marcar como Visto

**`POST /api/v1/announcements/{id}/seen`**

Chamado quando o usuário clica em "Ler agora" ou abre o modal.

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `store_id` | number (opcional) | Contexto da loja |

**Resposta:**
```json
{
  "data": {
    "message": "Marcado como visto.",
    "seen_at": "2026-01-12T19:00:00Z"
  }
}
```

---

### 5. Confirmar Recebimento (ACK)

**`POST /api/v1/announcements/{id}/ack`**

Chamado quando o usuário clica no botão "RECEBIDO" (obrigatório para advertências).

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `store_id` | number (opcional) | Contexto da loja |

**Resposta:**
```json
{
  "data": {
    "message": "Confirmação registrada.",
    "acknowledged_at": "2026-01-12T19:00:00Z"
  }
}
```

> **Importante:** Após ACK, o aviso não aparece mais em `critical` ou como pendente.

---

### 6. Dispensar Aviso

**`POST /api/v1/announcements/{id}/dismiss`**

Apenas para avisos sem `require_ack`. Remove do carrossel.

---

### 7. Listar Avisos (Admin)

**`GET /api/v1/announcements`**

Lista todos os avisos que o usuário tem permissão de gerenciar.

| Parâmetro | Tipo | Descrição |
|-----------|------|-----------|
| `status` | string | `draft`, `scheduled`, `active`, `expired`, `archived`, `all` |
| `scope` | string | Filtrar por escopo |
| `severity` | string | Filtrar por severidade |
| `store_id` | number | Filtrar por loja alvo |
| `created_by` | number | Filtrar por criador |
| `date_from` | string | Data inicial (created_at) |
| `date_to` | string | Data final |

---

### 8. Criar Aviso

**`POST /api/v1/announcements`**

**Body:**
```json
{
  "title": "Título do aviso",
  "message": "<p>Conteúdo HTML ou texto</p>",
  "excerpt": "Resumo curto (opcional, auto-gerado se omitido)",
  "type": "recado",
  "severity": "info",
  "display_mode": "banner",
  "scope": "store",
  "require_ack": false,
  "starts_at": "2026-01-13T09:00:00Z",
  "expires_at": "2026-01-20T18:00:00Z",
  "priority": 10,
  "targets": [
    { "target_type": "store", "target_id": "1" },
    { "target_type": "store", "target_id": "2" }
  ]
}
```

**Regras de validação:**
- Se `scope !== 'global'`, `targets` é obrigatório
- Se `type === 'advertencia'`, `severity` deve ser `'danger'`
- Se `display_mode === 'modal'`, `require_ack` é automaticamente `true`
- `repeat_every_minutes` só é permitido quando `require_ack === true`

**Status inicial:** Sempre `draft`. Use `/publish` para ativar.

---

### 9. Atualizar Aviso

**`PUT /api/v1/announcements/{id}`**

Mesma estrutura do create, mas campos são opcionais.

> **Restrição:** Avisos `active` não podem ter `scope`, `type` ou `targets` alterados.

---

### 10. Excluir Aviso

**`DELETE /api/v1/announcements/{id}`**

Soft delete. O aviso é mantido no banco mas não aparece mais.

---

### 11. Publicar Aviso

**`POST /api/v1/announcements/{id}/publish`**

Muda o status de `draft` para `active` (ou `scheduled` se `starts_at` for futuro).

**Resposta:**
```json
{
  "data": {
    "message": "Publicado com sucesso.",
    "status": "active",
    "published_at": "2026-01-12T19:00:00Z"
  }
}
```

---

### 12. Arquivar Aviso

**`POST /api/v1/announcements/{id}/archive`**

Muda o status para `archived`. O aviso não aparece mais para nenhum usuário.

---

## Permissões (RBAC)

### Matriz de Permissões

| Ação | Super Admin | Admin | Gerente | Conferente | Vendedor |
|------|:-----------:|:-----:|:-------:|:----------:|:--------:|
| Ver avisos elegíveis | ✅ | ✅ | ✅ | ✅ | ✅ |
| Marcar como visto | ✅ | ✅ | ✅ | ✅ | ✅ |
| Confirmar (ACK) | ✅ | ✅ | ✅ | ✅ | ✅ |
| Dispensar | ✅ | ✅ | ✅ | ✅ | ✅ |
| Listar todos (admin) | ✅ | ✅ | ✅ | ❌ | ❌ |
| Criar global | ✅ | ✅ | ❌ | ❌ | ❌ |
| Criar store/role | ✅ | ✅ | ✅* | ❌ | ❌ |
| Editar | ✅ | ✅ | ✅* | ❌ | ❌ |
| Publicar | ✅ | ✅ | ✅* | ❌ | ❌ |
| Arquivar | ✅ | ✅ | ✅* | ❌ | ❌ |
| Excluir | ✅ | ✅ | ✅* | ❌ | ❌ |

*Apenas para lojas onde é gerente.

### Verificando Permissões no Frontend

```typescript
// Baseado no endpoint /me
interface UserPermissions {
  is_super_admin: boolean;
  stores: Array<{
    id: number;
    name: string;
    role: 'admin' | 'gerente' | 'conferente' | 'vendedor';
  }>;
}

function canManageAnnouncements(user: UserPermissions): boolean {
  if (user.is_super_admin) return true;
  return user.stores.some(s => ['admin', 'gerente'].includes(s.role));
}

function canCreateGlobal(user: UserPermissions): boolean {
  if (user.is_super_admin) return true;
  return user.stores.some(s => s.role === 'admin');
}

function canManageForStore(user: UserPermissions, storeId: number): boolean {
  if (user.is_super_admin) return true;
  return user.stores.some(
    s => s.id === storeId && ['admin', 'gerente'].includes(s.role)
  );
}
```

---

## Fluxos de Tela

### 1. Dashboard com Avisos

```
┌─────────────────────────────────────────────────────────────────┐
│ Dashboard                                                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ 🔔 Você tem 1 aviso importante                     [Ver]   │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                  │
│  ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐   │
│  │ 📢 Novo Horário │ │ 🎯 Meta do Mês  │ │ 📋 Treinamento  │   │
│  │ A partir de...  │ │ Confira as...   │ │ Dia 15/01...    │   │
│  │ [Ler agora]     │ │ [Ler agora]     │ │ [Ler agora]     │   │
│  └─────────────────┘ └─────────────────┘ └─────────────────┘   │
│  ● ○ ○                                                          │
│                                                                  │
│  ... resto do dashboard ...                                      │
└─────────────────────────────────────────────────────────────────┘
```

**Componentes:**
1. **Banner de Alerta** (para `critical`): Fundo vermelho, botão "Ver" abre modal
2. **Carrossel de Banners**: Rotação automática, indicadores de página
3. **Cards de Banner**: Ícone, título, excerpt, botão CTA

### 2. Modal de Advertência (Critical)

```
┌─────────────────────────────────────────────────────────────────┐
│                    ⚠️ ATENÇÃO                              [X]  │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Você tem uma mensagem importante do administrador.              │
│                                                                  │
│  ─────────────────────────────────────────────────────────────  │
│                                                                  │
│  Comunicado sobre nova política de vendas                        │
│                                                                  │
│  A partir do dia 15/01, todas as vendas acima de R$ 500          │
│  precisarão de aprovação do gerente. Certifique-se de            │
│  seguir o novo procedimento.                                     │
│                                                                  │
│  ─────────────────────────────────────────────────────────────  │
│                                                                  │
│                    ┌─────────────────────┐                      │
│                    │    ✓ RECEBIDO       │                      │
│                    └─────────────────────┘                      │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

**Comportamento:**
- Aparece automaticamente quando há itens em `critical`
- Botão X só fecha se `require_ack === false`
- Botão "RECEBIDO" chama `/ack` e fecha o modal
- Fundo vermelho/danger, botão grande e destacado

### 3. Modal de Leitura (Banner)

```
┌─────────────────────────────────────────────────────────────────┐
│ Novo Horário de Funcionamento                              [X]  │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  📅 Publicado em 12/01/2026                                      │
│                                                                  │
│  ─────────────────────────────────────────────────────────────  │
│                                                                  │
│  A partir de segunda-feira, o horário de funcionamento           │
│  será das 09:00 às 18:00.                                        │
│                                                                  │
│  Lembre-se de ajustar seus horários de entrada e saída.          │
│                                                                  │
│  ─────────────────────────────────────────────────────────────  │
│                                                                  │
│  ┌─────────────┐                         ┌─────────────────┐    │
│  │  Dispensar  │                         │      Fechar     │    │
│  └─────────────┘                         └─────────────────┘    │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

**Comportamento:**
- Ao abrir, chama `/seen`
- Botão "Dispensar" chama `/dismiss` (se `require_ack === false`)
- Botão "Fechar" apenas fecha

### 4. Tela de Histórico de Comunicados

```
┌─────────────────────────────────────────────────────────────────┐
│ 📬 Meus Comunicados                                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Filtros: [Todos ▼] [Não lidos ☐] [Pendentes ☐]              │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                  │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ 🔴 Advertência - Política de Vendas            12/01 14:30  │ │
│ │ Status: ✓ Confirmado                                        │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                  │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ 🔵 Recado - Novo Horário                       12/01 10:00  │ │
│ │ Status: 👁 Visualizado                                       │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                  │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ 🟡 Recado - Treinamento Obrigatório            10/01 09:00  │ │
│ │ Status: ⏳ Pendente                                          │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                  │
│                    [← 1 2 3 ... →]                              │
└─────────────────────────────────────────────────────────────────┘
```

### 5. Tela de Administração de Comunicados

```
┌─────────────────────────────────────────────────────────────────┐
│ ⚙️ Gerenciar Comunicados                          [+ Novo]      │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Status: [Todos ▼]  Tipo: [Todos ▼]  Escopo: [Todos ▼]       │ │
│ │ Período: [__/__/__] a [__/__/__]                   [Filtrar]│ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                  │
│ ┌───────────────────────────────────────────────────────────────┐
│ │ Título          │ Tipo     │ Escopo │ Status   │ Ações       │
│ ├───────────────────────────────────────────────────────────────┤
│ │ Política Vendas │ Advert.  │ Global │ 🟢 Ativo │ [···]       │
│ │ Novo Horário    │ Recado   │ Loja   │ 🟢 Ativo │ [···]       │
│ │ Treinamento     │ Recado   │ Cargo  │ 🔵 Agend │ [···]       │
│ │ Promoção Natal  │ Recado   │ Global │ ⚪ Rasc  │ [···]       │
│ └───────────────────────────────────────────────────────────────┘
│                                                                  │
│                    [← 1 2 3 ... →]                              │
└─────────────────────────────────────────────────────────────────┘
```

**Menu de Ações (···):**
- 👁 Visualizar
- ✏️ Editar (apenas draft/scheduled)
- 📤 Publicar (apenas draft)
- 📥 Arquivar
- 🗑️ Excluir

### 6. Formulário de Criação/Edição

```
┌─────────────────────────────────────────────────────────────────┐
│ ➕ Novo Comunicado                                               │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│ Informações Básicas                                              │
│ ───────────────────────────────────────────────────────────────  │
│                                                                  │
│ Título *                                                         │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │                                                             │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                  │
│ Mensagem *                                                       │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ [B] [I] [U] [Link] [Lista]                                  │ │
│ ├─────────────────────────────────────────────────────────────┤ │
│ │                                                             │ │
│ │                                                             │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                  │
│ Tipo *                           Severidade *                    │
│ ┌──────────────────────┐        ┌──────────────────────┐        │
│ │ ○ Recado             │        │ ○ Informativo        │        │
│ │ ○ Advertência        │        │ ○ Atenção            │        │
│ └──────────────────────┘        │ ○ Urgente            │        │
│                                 └──────────────────────┘        │
│                                                                  │
│ Exibição                                                         │
│ ───────────────────────────────────────────────────────────────  │
│                                                                  │
│ Modo de Exibição                 ☐ Exigir Confirmação            │
│ ┌──────────────────────┐                                        │
│ │ ○ Banner (carrossel) │                                        │
│ │ ○ Modal (pop-up)     │                                        │
│ │ ○ Ambos              │                                        │
│ └──────────────────────┘                                        │
│                                                                  │
│ Segmentação                                                      │
│ ───────────────────────────────────────────────────────────────  │
│                                                                  │
│ Escopo *                                                         │
│ ┌──────────────────────┐                                        │
│ │ ○ Global (todos)     │ ← Desabilitado se não for admin        │
│ │ ○ Lojas específicas  │                                        │
│ │ ○ Usuários           │                                        │
│ │ ○ Cargos             │                                        │
│ └──────────────────────┘                                        │
│                                                                  │
│ Selecione os alvos:                                              │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ ☑ Loja Centro    ☑ Loja Shopping    ☐ Loja Bairro          │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                  │
│ Agendamento                                                      │
│ ───────────────────────────────────────────────────────────────  │
│                                                                  │
│ Início                           Expiração                       │
│ ┌──────────────────────┐        ┌──────────────────────┐        │
│ │ 📅 13/01/2026 09:00  │        │ 📅 20/01/2026 18:00  │        │
│ └──────────────────────┘        └──────────────────────┘        │
│                                                                  │
│ ┌───────────────────┐     ┌───────────────────────────────────┐ │
│ │    Salvar Rascunho│     │       Salvar e Publicar          │ │
│ └───────────────────┘     └───────────────────────────────────┘ │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Boas Práticas

### 1. Fetch de Dados

```typescript
// Hook para avisos ativos
function useActiveAnnouncements(storeId?: number) {
  const { data, error, mutate } = useSWR(
    `/api/v1/me/announcements/active${storeId ? `?store_id=${storeId}` : ''}`,
    fetcher,
    {
      refreshInterval: 5 * 60 * 1000, // 5 minutos
      revalidateOnFocus: true,
    }
  );

  return {
    critical: data?.data?.critical ?? [],
    banners: data?.data?.banners ?? [],
    isLoading: !error && !data,
    error,
    refresh: mutate,
  };
}
```

### 2. Tratamento de Erros

```typescript
// Erros comuns
const errorMessages: Record<number, string> = {
  401: 'Sessão expirada. Faça login novamente.',
  403: 'Você não tem permissão para esta ação.',
  404: 'Comunicado não encontrado.',
  422: 'Dados inválidos. Verifique os campos.',
};

async function handleApiError(response: Response) {
  const status = response.status;
  const data = await response.json();
  
  if (status === 422 && data.errors) {
    // Erros de validação
    return { validationErrors: data.errors };
  }
  
  throw new Error(errorMessages[status] || 'Erro inesperado.');
}
```

### 3. Otimistic Updates

```typescript
async function acknowledgeAnnouncement(id: number) {
  // Atualiza UI imediatamente
  mutate(
    '/api/v1/me/announcements/active',
    (current) => ({
      ...current,
      data: {
        ...current.data,
        critical: current.data.critical.filter(a => a.id !== id),
      },
    }),
    false
  );

  try {
    await api.post(`/announcements/${id}/ack`);
    // Revalida em background
    mutate('/api/v1/me/announcements/active');
  } catch (error) {
    // Reverte em caso de erro
    mutate('/api/v1/me/announcements/active');
    throw error;
  }
}
```

### 4. Cache e Performance

```typescript
// Pré-carregar detalhes ao hover
function AnnouncementCard({ announcement }) {
  const prefetch = () => {
    mutate(
      `/api/v1/announcements/${announcement.id}`,
      fetcher(`/api/v1/announcements/${announcement.id}`),
      false
    );
  };

  return (
    <Card onMouseEnter={prefetch}>
      {/* ... */}
    </Card>
  );
}
```

### 5. Validação no Frontend

```typescript
import { z } from 'zod';

const createAnnouncementSchema = z.object({
  title: z.string().min(1, 'Título obrigatório').max(120),
  message: z.string().min(1, 'Mensagem obrigatória'),
  type: z.enum(['recado', 'advertencia']),
  severity: z.enum(['info', 'warning', 'danger']),
  scope: z.enum(['global', 'store', 'user', 'role']),
  targets: z.array(z.object({
    target_type: z.enum(['store', 'user', 'role']),
    target_id: z.string(),
  })).optional(),
})
.refine(
  (data) => data.scope === 'global' || (data.targets && data.targets.length > 0),
  { message: 'Selecione pelo menos um alvo', path: ['targets'] }
)
.refine(
  (data) => data.type !== 'advertencia' || data.severity === 'danger',
  { message: 'Advertências devem ter severidade "Urgente"', path: ['severity'] }
);
```

### 6. Estados de Loading

```typescript
// Skeleton para carrossel
function AnnouncementCarouselSkeleton() {
  return (
    <div className="flex gap-4 overflow-hidden">
      {[1, 2, 3].map((i) => (
        <div key={i} className="w-64 h-32 bg-gray-200 animate-pulse rounded-lg" />
      ))}
    </div>
  );
}
```

---

## Exemplos de Código

### Service API

```typescript
// services/announcements.ts
import { api } from '@/lib/api';

export const announcementsService = {
  // User endpoints
  getActive: (storeId?: number) =>
    api.get<ActiveAnnouncementsResponse>('/me/announcements/active', {
      params: { store_id: storeId },
    }),

  getHistory: (filters: AnnouncementFilters) =>
    api.get<PaginatedResponse<AnnouncementSummary>>('/me/announcements', {
      params: filters,
    }),

  markSeen: (id: number, storeId?: number) =>
    api.post(`/announcements/${id}/seen`, { store_id: storeId }),

  acknowledge: (id: number, storeId?: number) =>
    api.post(`/announcements/${id}/ack`, { store_id: storeId }),

  dismiss: (id: number, storeId?: number) =>
    api.post(`/announcements/${id}/dismiss`, { store_id: storeId }),

  // Admin endpoints
  list: (filters: AnnouncementFilters) =>
    api.get<PaginatedResponse<AnnouncementDetail>>('/announcements', {
      params: filters,
    }),

  get: (id: number) =>
    api.get<AnnouncementDetail>(`/announcements/${id}`),

  create: (data: CreateAnnouncementPayload) =>
    api.post<AnnouncementDetail>('/announcements', data),

  update: (id: number, data: UpdateAnnouncementPayload) =>
    api.put<AnnouncementDetail>(`/announcements/${id}`, data),

  delete: (id: number) =>
    api.delete(`/announcements/${id}`),

  publish: (id: number) =>
    api.post<{ message: string; status: string; published_at: string }>(
      `/announcements/${id}/publish`
    ),

  archive: (id: number) =>
    api.post<{ message: string; archived_at: string }>(
      `/announcements/${id}/archive`
    ),
};
```

### Componente de Banner Crítico

```tsx
// components/CriticalAnnouncementModal.tsx
import { useState } from 'react';
import { Dialog } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { AlertTriangle } from 'lucide-react';
import { announcementsService } from '@/services/announcements';

interface Props {
  announcement: AnnouncementSummary;
  onAcknowledge: () => void;
}

export function CriticalAnnouncementModal({ announcement, onAcknowledge }: Props) {
  const [loading, setLoading] = useState(false);

  const handleAck = async () => {
    setLoading(true);
    try {
      await announcementsService.acknowledge(announcement.id);
      onAcknowledge();
    } catch (error) {
      console.error('Failed to acknowledge:', error);
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog open onOpenChange={() => {}}>
      <div className="bg-red-50 border-2 border-red-500 rounded-xl p-6">
        <div className="flex items-center gap-3 mb-4">
          <AlertTriangle className="w-8 h-8 text-red-600" />
          <h2 className="text-xl font-bold text-red-900">ATENÇÃO</h2>
        </div>

        <p className="text-gray-600 mb-4">
          Você tem uma mensagem importante do administrador.
        </p>

        <hr className="my-4" />

        <h3 className="font-semibold text-lg mb-2">{announcement.title}</h3>
        <div 
          className="prose prose-sm"
          dangerouslySetInnerHTML={{ __html: announcement.message }}
        />

        <hr className="my-4" />

        <div className="flex justify-center">
          <Button
            size="lg"
            className="bg-red-600 hover:bg-red-700 text-white px-8 py-4 text-lg"
            onClick={handleAck}
            disabled={loading}
          >
            {loading ? 'Processando...' : '✓ RECEBIDO'}
          </Button>
        </div>
      </div>
    </Dialog>
  );
}
```

---

## Cores e Estilos Sugeridos

```css
/* Severities */
.severity-info { background: #EFF6FF; border-color: #3B82F6; color: #1E40AF; }
.severity-warning { background: #FFFBEB; border-color: #F59E0B; color: #92400E; }
.severity-danger { background: #FEF2F2; border-color: #EF4444; color: #991B1B; }

/* Status badges */
.status-draft { background: #F3F4F6; color: #6B7280; }
.status-scheduled { background: #DBEAFE; color: #1D4ED8; }
.status-active { background: #D1FAE5; color: #065F46; }
.status-expired { background: #FED7AA; color: #9A3412; }
.status-archived { background: #E5E7EB; color: #4B5563; }
```

---

## Checklist de Implementação

### Dashboard
- [ ] Componente de banner de alerta crítico
- [ ] Componente de carrossel de banners
- [ ] Modal de advertência com botão RECEBIDO
- [ ] Modal de leitura de comunicado
- [ ] Hook `useActiveAnnouncements`
- [ ] Polling ou WebSocket para novos avisos

### Histórico
- [ ] Página de listagem de comunicados recebidos
- [ ] Filtros (status, tipo, data)
- [ ] Paginação
- [ ] Indicadores de status (lido/pendente/confirmado)

### Administração
- [ ] Página de listagem de comunicados
- [ ] Formulário de criação
- [ ] Formulário de edição
- [ ] Seletor de alvos (lojas/usuários/cargos)
- [ ] Ações: publicar, arquivar, excluir
- [ ] Validação de permissões por escopo
