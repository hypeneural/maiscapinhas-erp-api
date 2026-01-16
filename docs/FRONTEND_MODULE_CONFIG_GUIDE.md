# 📬 Respostas Completas do Backend - Configuração de Módulos

> **De:** Backend Team  
> **Para:** Frontend Team  
> **Data:** 16/01/2026 15:00  
> **Assunto:** RE: Endpoints de Edição de Status, Textos e Sugestões de UX  
> **Status:** ✅ TODOS OS ENDPOINTS IMPLEMENTADOS!

---

## ✅ Checklist de Respostas

| # | Pergunta | Resposta |
|---|----------|----------|
| 1 | Endpoint de editar status existe? | ✅ **SIM** - `PATCH /modules/{id}/statuses/{key}` |
| 2 | Quais campos de status são editáveis? | ✅ Ver tabela abaixo |
| 3 | Endpoint de criar status existe? | ✅ **SIM** - `POST /modules/{id}/statuses` |
| 4 | Endpoint de deletar status existe? | ✅ **SIM** - `DELETE /modules/{id}/statuses/{key}` |
| 5 | Endpoint de editar textos existe? | ✅ **SIM** - `PUT /modules/{id}/texts` |
| 6 | Tooltips dinâmicos são viáveis? | ✅ **SIM** - Campos `tooltip` e `help_text` suportados |
| 7 | Validation rules é viável? | ✅ **SIM** - `GET /modules/{id}/schema` |

---

## 📋 Lista Completa de Endpoints de Módulos

### Base URL: `/api/v1/admin/modules`

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/` | Listar módulos |
| GET | `/{id}` | Detalhes básicos |
| GET | `/{id}/full` | Configuração completa |
| GET | `/{id}/schema` | **NOVO** - Regras de validação |
| GET | `/{id}/stores` | Listar lojas com status de ativação |
| GET | `/{id}/transitions` | Matriz de transições |
| GET | `/{id}/audit-log` | Histórico de alterações |
| PUT | `/{id}/texts` | Editar textos/labels |
| PUT | `/{id}/transitions` | Editar matriz de transições |
| POST | `/{id}/install` | Instalar módulo |
| POST | `/{id}/activate` | Ativar globalmente |
| POST | `/{id}/deactivate` | Desativar globalmente |
| POST | `/{id}/stores/{storeId}/activate` | Ativar para loja |
| POST | `/{id}/stores/{storeId}/deactivate` | Desativar para loja |
| POST | `/{id}/statuses` | **NOVO** - Criar status |
| PATCH | `/{id}/statuses/{key}` | **NOVO** - Editar status |
| DELETE | `/{id}/statuses/{key}` | **NOVO** - Deletar status |
| POST | `/{id}/preview-impact` | **NOVO** - Preview de impacto |
| PUT | `/{id}/actions/{action}` | Editar ação |
| POST | `/{id}/actions` | Criar ação |
| DELETE | `/{id}/actions/{action}` | Deletar ação |

---

## 1. Edição de Status

### Campos Editáveis

| Campo | Editável? | Tipo | Validação |
|-------|-----------|------|-----------|
| `name` (slug) | ⚠️ Somente em novos | string | `/^[a-z_]+$/`, max:50 |
| `label` | ✅ **SIM** | string | min:2, max:50 |
| `description` | ✅ **SIM** | string | max:255 |
| `color` | ✅ **SIM** | enum | blue,red,yellow,green,purple,gray,orange,cyan,pink |
| `icon` | ✅ **SIM** | enum | Ver lista completa abaixo |
| `badge_variant` | ✅ **SIM** | enum | default,destructive,outline,secondary,success,warning |
| `can_edit` | ✅ **SIM** | boolean | - |
| `final` | ✅ **SIM** | boolean | ⚠️ Afeta transições |
| `tooltip` | ✅ **SIM** | string | max:255 |
| `help_text` | ✅ **SIM** | string | max:500 |

### PATCH - Editar Status Existente

```http
PATCH /api/v1/admin/modules/pedidos-simples/statuses/3
Authorization: Bearer {token}
Content-Type: application/json

{
  "label": "Disponível para Retirada",
  "color": "green",
  "icon": "CheckCircle",
  "tooltip": "Produto pronto para ser retirado pelo cliente",
  "help_text": "Este status indica que o vendedor deve avisar o cliente"
}
```

**Response:**
```json
{
  "message": "Status '3' atualizado.",
  "data": {
    "name": "disponivel",
    "label": "Disponível para Retirada",
    "color": "green",
    "icon": "CheckCircle",
    "tooltip": "Produto pronto para ser retirado pelo cliente",
    "help_text": "Este status indica que o vendedor deve avisar o cliente",
    "badge_variant": "success",
    "can_edit": false,
    "final": false
  }
}
```

### POST - Criar Novo Status

```http
POST /api/v1/admin/modules/pedidos-simples/statuses
Authorization: Bearer {token}
Content-Type: application/json

{
  "key": "7",
  "status": {
    "name": "em_analise",
    "label": "Em Análise",
    "description": "Pedido está sendo analisado pelo gerente",
    "color": "purple",
    "icon": "Eye",
    "badge_variant": "outline",
    "can_edit": true,
    "final": false,
    "tooltip": "O gerente está verificando este pedido",
    "help_text": "Aguarde aprovação do gerente para prosseguir"
  },
  "transitions_to": ["3", "6"],
  "transitions_from": ["1", "2"]
}
```

**Response (201 Created):**
```json
{
  "message": "Status '7' criado.",
  "data": {
    "name": "em_analise",
    "label": "Em Análise",
    "_custom": true,
    "_created_at": "2026-01-16T15:00:00Z"
  },
  "all_statuses": { ... }
}
```

### DELETE - Deletar Status

```http
DELETE /api/v1/admin/modules/pedidos-simples/statuses/7
Authorization: Bearer {token}
```

**Response (se sem registros afetados):**
```json
{
  "message": "Status '7' removido.",
  "all_statuses": { ... }
}
```

**Response (se há registros - 409 Conflict):**
```json
{
  "message": "Existem registros neste status. Use force=true para confirmar.",
  "impact": {
    "status_key": "7",
    "can_proceed": false,
    "affected_records": 42,
    "warnings": [
      "42 registros estão neste status.",
      "2 status(s) tem transição para este status.",
      "Este status tem 2 transição(ões) de saída.",
      "2 ação(ões) estão vinculadas a este status: avisar_cliente, finalizar_venda"
    ],
    "suggestions": [
      "Mova os registros para outro status antes de deletar."
    ]
  }
}
```

> **⚠️ Importante:** Status base do módulo NÃO podem ser deletados, apenas customizados.

---

## 2. Edição de Textos

### PUT - Editar Textos

```http
PUT /api/v1/admin/modules/pedidos-simples/texts
Authorization: Bearer {token}
Content-Type: application/json

{
  "texts": {
    "menu_label": "Pedidos de Encomenda",
    "menu_tooltip": "Gerenciar pedidos de produtos",
    "page_title": "Lista de Pedidos",
    "page_description": "Acompanhe todos os pedidos de encomenda",
    "create_button": "Novo Pedido",
    "empty_state": "Nenhum pedido encontrado"
  }
}
```

**Response:**
```json
{
  "message": "Textos atualizados.",
  "data": {
    "menu_label": "Pedidos de Encomenda",
    "menu_tooltip": "Gerenciar pedidos de produtos",
    // ... todos os textos mesclados com defaults
  }
}
```

### Textos Disponíveis

| Campo | Max | Descrição |
|-------|-----|-----------|
| `menu_label` | 100 | Label no menu lateral |
| `menu_tooltip` | 255 | Tooltip ao passar o mouse |
| `page_title` | 100 | Título da página |
| `page_description` | 500 | Descrição/subtítulo |
| `create_button` | 50 | Texto do botão criar |
| `empty_state` | 255 | Mensagem quando lista vazia |
| `loading_title` | 100 | Título durante loading |
| `loading_description` | 255 | Descrição durante loading |
| `error_title` | 100 | Título de erro |
| `error_description` | 255 | Descrição de erro |
| `retry_button` | 50 | Texto do botão tentar novamente |

---

## 3. Schema de Validação

### GET - Buscar Schema

```http
GET /api/v1/admin/modules/pedidos-simples/schema
Authorization: Bearer {token}
```

**Response:**
```json
{
  "module_id": "pedidos-simples",
  "schema": {
    "texts": {
      "menu_label": { "type": "string", "required": false, "min": 1, "max": 100 },
      "page_title": { "type": "string", "required": false, "min": 1, "max": 100 },
      "page_description": { "type": "string", "required": false, "max": 500 }
    },
    "status": {
      "name": { 
        "type": "string", 
        "required": true, 
        "pattern": "^[a-z_]+$", 
        "max": 50,
        "hint": "Slug interno (ex: aguardando_cliente)"
      },
      "label": { "type": "string", "required": true, "min": 2, "max": 50 },
      "description": { "type": "string", "required": false, "max": 255 },
      "color": { "type": "enum", "allowed": ["blue", "red", "yellow", "green", "purple", "gray", "orange", "cyan", "pink"] },
      "icon": { "type": "enum", "allowed": ["FileCheck", "Truck", "Store", ...] },
      "badge_variant": { "type": "enum", "allowed": ["default", "destructive", "outline", "secondary", "success", "warning"] },
      "can_edit": { "type": "boolean", "default": true },
      "final": { "type": "boolean", "default": false, "hint": "Status final encerra o fluxo" },
      "tooltip": { "type": "string", "required": false, "max": 255 },
      "help_text": { "type": "string", "required": false, "max": 500 }
    },
    "action": {
      "label": { "type": "string", "required": true, "max": 50 },
      "icon": { "type": "enum", "allowed": [...] },
      "tooltip": { "type": "string", "required": false, "max": 255 },
      "permission": { "type": "string", "required": false, "pattern": "^[a-z._-]+$" },
      "available_in_status": { "type": "array", "items": "integer" },
      "confirm": { "type": "boolean", "default": false },
      "confirm_title": { "type": "string", "max": 100 },
      "confirm_message": { "type": "string", "max": 500 },
      "requires_fields": { "type": "array", "items": "string" }
    }
  },
  "allowed_values": {
    "icons": [
      "FileCheck", "Truck", "Store", "Bell", "CheckCircle", "XCircle",
      "AlertCircle", "Clock", "User", "UserCheck", "Package", "Send",
      "Plus", "Edit", "Trash", "Eye", "Settings", "Shield", "Key",
      "Palette", "LayoutDashboard", "ClipboardList", "CreditCard"
    ],
    "colors": ["blue", "red", "yellow", "green", "purple", "gray", "orange", "cyan", "pink"],
    "badge_variants": ["default", "destructive", "outline", "secondary", "success", "warning"]
  }
}
```

---

## 4. Preview de Impacto

### POST - Verificar Impacto Antes de Alterar

```http
POST /api/v1/admin/modules/pedidos-simples/preview-impact
Authorization: Bearer {token}
Content-Type: application/json

{
  "action": "delete_status",
  "status_key": "3"
}
```

**Response:**
```json
{
  "action": "delete_status",
  "status_key": "3",
  "can_proceed": false,
  "affected_records": 42,
  "warnings": [
    "42 registros estão neste status.",
    "2 status(s) tem transição para este status.",
    "Este status tem 2 transição(ões) de saída.",
    "2 ação(ões) estão vinculadas a este status: avisar_cliente, finalizar_venda"
  ],
  "suggestions": [
    "Mova os registros para outro status antes de deletar."
  ]
}
```

### Ações Suportadas

| Action | Campos Requeridos |
|--------|-------------------|
| `delete_status` | `status_key` |
| `update_status` | `status_key`, `changes` |
| `update_transition` | `changes` |
| `delete_action` | `changes.action_key` |

---

## 5. Histórico de Alterações

### GET - Audit Log

```http
GET /api/v1/admin/modules/pedidos-simples/audit-log?limit=20
Authorization: Bearer {token}
```

**Response:**
```json
{
  "module_id": "pedidos-simples",
  "entries": [
    {
      "action": "status_updated",
      "data": {
        "status_key": "3",
        "changes": { "label": "Disponível para Retirada" }
      },
      "user_id": 11,
      "user_name": "Super Admin",
      "timestamp": "2026-01-16T15:00:00Z",
      "ip_address": "192.168.1.1"
    },
    {
      "action": "texts_updated",
      "data": { "menu_label": "Pedidos de Encomenda" },
      "user_id": 11,
      "user_name": "Super Admin",
      "timestamp": "2026-01-16T14:55:00Z",
      "ip_address": "192.168.1.1"
    }
  ],
  "total": 15
}
```

### Tipos de Ação no Audit

| Action | Descrição |
|--------|-----------|
| `status_created` | Novo status criado |
| `status_updated` | Status editado |
| `status_deleted` | Status removido |
| `texts_updated` | Textos editados |
| `action_created` | Nova ação criada |
| `action_updated` | Ação editada |
| `action_deleted` | Ação removida |
| `transitions_updated` | Matriz de transições alterada |

---

## 📋 TypeScript Interfaces

```typescript
// ================================
// Schema
// ================================
interface FieldSchema {
  type: 'string' | 'boolean' | 'enum' | 'array';
  required?: boolean;
  min?: number;
  max?: number;
  pattern?: string;
  allowed?: string[];
  items?: string;
  default?: unknown;
  hint?: string;
}

interface ModuleSchema {
  module_id: string;
  schema: {
    texts: Record<string, FieldSchema>;
    status: Record<string, FieldSchema>;
    action: Record<string, FieldSchema>;
  };
  allowed_values: {
    icons: string[];
    colors: string[];
    badge_variants: string[];
  };
}

// ================================
// Status
// ================================
interface ModuleStatus {
  name: string;
  label: string;
  description?: string;
  color: string;
  icon: string;
  badge_variant: string;
  can_edit: boolean;
  final: boolean;
  tooltip?: string;
  help_text?: string;
  _custom?: boolean;
  _created_at?: string;
}

interface CreateStatusRequest {
  key: string;
  status: Omit<ModuleStatus, '_custom' | '_created_at'>;
  transitions_to?: string[];
  transitions_from?: string[];
}

interface UpdateStatusRequest {
  label?: string;
  description?: string;
  color?: string;
  icon?: string;
  badge_variant?: string;
  can_edit?: boolean;
  final?: boolean;
  tooltip?: string;
  help_text?: string;
}

// ================================
// Preview Impact
// ================================
interface PreviewImpactRequest {
  action: 'delete_status' | 'update_status' | 'update_transition' | 'delete_action';
  status_key?: string;
  changes?: Record<string, unknown>;
}

interface PreviewImpactResponse {
  action: string;
  status_key?: string;
  can_proceed: boolean;
  affected_records: number;
  warnings: string[];
  suggestions: string[];
}

// ================================
// Audit Log
// ================================
interface AuditEntry {
  action: string;
  data: Record<string, unknown>;
  user_id: number;
  user_name: string;
  timestamp: string;
  ip_address: string;
}
```

---

## 🎨 Sugestões de UX/UI Aprovadas

### 1. Tooltips Dinâmicos ✅

Os campos `tooltip` e `help_text` já estão suportados. Exemplo de uso:

```tsx
<Tooltip content={status.tooltip}>
  <Badge variant={status.badge_variant}>{status.label}</Badge>
</Tooltip>

{status.help_text && (
  <p className="text-sm text-muted-foreground">{status.help_text}</p>
)}
```

### 2. Validação via Schema ✅

```tsx
const { data: schema } = useModuleSchema(moduleId);

// Aplicar validações do backend
const labelRules = schema.schema.status.label;
// { type: 'string', required: true, min: 2, max: 50 }
```

### 3. Preview Antes de Deletar ✅

```tsx
const handleDelete = async (statusKey: string) => {
  const impact = await previewImpact(moduleId, { 
    action: 'delete_status', 
    status_key: statusKey 
  });

  if (!impact.can_proceed) {
    showWarningDialog({
      title: 'Atenção',
      warnings: impact.warnings,
      suggestions: impact.suggestions,
      onConfirm: () => deleteStatus(moduleId, statusKey, { force: true })
    });
    return;
  }

  await deleteStatus(moduleId, statusKey);
};
```

---

## 🚀 Service Layer Sugerido

```typescript
// services/modules.service.ts

// Schema
export const getModuleSchema = (moduleId: string) =>
  api.get<ModuleSchema>(`/admin/modules/${moduleId}/schema`);

// Status CRUD
export const updateStatus = (moduleId: string, statusKey: string, data: UpdateStatusRequest) =>
  api.patch(`/admin/modules/${moduleId}/statuses/${statusKey}`, data);

export const createStatus = (moduleId: string, data: CreateStatusRequest) =>
  api.post(`/admin/modules/${moduleId}/statuses`, data);

export const deleteStatus = (moduleId: string, statusKey: string, options?: { force?: boolean }) =>
  api.delete(`/admin/modules/${moduleId}/statuses/${statusKey}`, { params: options });

// Impact Preview
export const previewImpact = (moduleId: string, data: PreviewImpactRequest) =>
  api.post<PreviewImpactResponse>(`/admin/modules/${moduleId}/preview-impact`, data);

// Texts
export const updateTexts = (moduleId: string, texts: Record<string, string>) =>
  api.put(`/admin/modules/${moduleId}/texts`, { texts });

// Audit Log
export const getAuditLog = (moduleId: string, limit = 50) =>
  api.get<{ entries: AuditEntry[]; total: number }>(`/admin/modules/${moduleId}/audit-log`, { params: { limit } });
```

---

## 💡 Fluxo de UI Recomendado

### Edição de Status

```
┌─────────────────────────────────────────────────────────────────┐
│ 📝 Editar Status: Disponível na Loja                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ Label *                                                         │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Disponível para Retirada                                    │ │
│ └─────────────────────────────────────────────────────────────┘ │
│ ℹ️ Mín 2, máx 50 caracteres                                    │
│                                                                 │
│ Descrição                                                       │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Produto pronto para ser retirado                            │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ Cor      [🟢 green     ▼]    Ícone    [✓ CheckCircle    ▼]     │
│ Badge    [success      ▼]    Final?   [ ] Não                  │
│                                                                 │
│ Tooltip (exibido ao passar o mouse)                            │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Produto pronto para ser retirado pelo cliente               │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ Texto de Ajuda                                                  │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ O vendedor deve avisar o cliente via WhatsApp               │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│                              [Cancelar]  [💾 Salvar Alterações] │
└─────────────────────────────────────────────────────────────────┘
```

### Confirmação de Deleção

```
┌─────────────────────────────────────────────────────────────────┐
│ ⚠️ Atenção: Impacto da Deleção                                  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ Você está prestes a deletar o status "Em Análise".             │
│                                                                 │
│ ⚠️ Avisos:                                                      │
│ • 42 registros estão neste status                              │
│ • 2 status têm transição para este status                      │
│ • 2 ações estão vinculadas a este status                       │
│                                                                 │
│ 💡 Sugestões:                                                   │
│ • Mova os registros para outro status antes de deletar         │
│                                                                 │
│                       [Cancelar]  [🗑️ Deletar Mesmo Assim]      │
└─────────────────────────────────────────────────────────────────┘
```

---

*Backend Team - MaisCapinhas - 16/01/2026 15:00*
