# 📬 Respostas do Backend - Configuração de Módulos

> **De:** Backend Team  
> **Para:** Frontend Team  
> **Data:** 16/01/2026 14:55  
> **Assunto:** RE: Endpoints de Edição de Status, Textos e Sugestões de UX

---

## ✅ Checklist de Respostas Completo

| # | Pergunta | Resposta |
|---|----------|----------|
| 1 | Endpoint de editar status existe? | ❌ **NÃO EXISTE** (ainda) |
| 2 | Quais campos de status são editáveis? | ⏳ Ver proposta abaixo |
| 3 | Endpoint de criar status existe? | ❌ **NÃO EXISTE** |
| 4 | Endpoint de deletar status existe? | ❌ **NÃO EXISTE** |
| 5 | Endpoint de editar textos existe? | ✅ **SIM!** `PUT /admin/modules/{id}/texts` |
| 6 | Tooltips dinâmicos são viáveis? | ✅ **SIM!** Podemos adicionar |
| 7 | Validation rules é viável? | ✅ **SIM!** Podemos adicionar |

---

## 1. Edição de Status ❌ NÃO IMPLEMENTADO (Ainda)

### Status Atual

Os status são **definidos nos módulos PHP** e são considerados **estruturais**. 
Atualmente não há endpoints para criar/editar/deletar status.

### 🔧 Proposta de Implementação

Se o frontend precisa dessa funcionalidade, posso implementar:

```
PATCH /admin/modules/{moduleId}/statuses/{statusKey}
POST  /admin/modules/{moduleId}/statuses
DELETE /admin/modules/{moduleId}/statuses/{statusKey}
```

#### Campos que seriam editáveis:

| Campo | Editável? | Observação |
|-------|-----------|------------|
| `name` (slug interno) | ⚠️ **Somente em novos** | Não pode mudar depois de criado |
| `label` (nome exibido) | ✅ **SIM** | |
| `color` (cor) | ✅ **SIM** | Aceita cores do Tailwind ou hex |
| `description` | ✅ **SIM** | |
| `icon` (Lucide) | ✅ **SIM** | Validado contra lista de ícones |
| `badge_variant` | ✅ **SIM** | default, destructive, outline, secondary, success, warning |
| `can_edit` | ✅ **SIM** | |
| `final` | ⚠️ **Com validação** | Impacta fluxo de transições |

> **Deseja que eu implemente esses endpoints?** Estimado: ~2 horas

---

## 2. Edição de Textos ✅ JÁ EXISTE!

### Endpoint

```
PUT /api/v1/admin/modules/{moduleId}/texts
```

### Request Body

```json
{
  "texts": {
    "menu_label": "Meus Pedidos",
    "menu_tooltip": "Gerenciar pedidos de encomenda",
    "page_title": "Pedidos de Encomenda",
    "page_description": "Acompanhe todos os pedidos...",
    "create_button": "Novo Pedido",
    "empty_state": "Nenhum pedido encontrado",
    "loading_title": "Carregando pedidos...",
    "loading_description": "Aguarde...",
    "error_title": "Erro ao carregar",
    "error_description": "Não foi possível carregar",
    "retry_button": "Tentar novamente"
  }
}
```

### Response

```json
{
  "message": "Textos atualizados.",
  "data": {
    "menu_label": "Meus Pedidos",
    "menu_tooltip": "Gerenciar pedidos de encomenda",
    // ... todos os textos mesclados
  }
}
```

### Validações Aplicadas

| Campo | Validação |
|-------|-----------|
| `menu_label` | string, max:100 |
| `menu_tooltip` | string, max:255 |
| `page_title` | string, max:100 |
| `page_description` | string, max:500 |
| `create_button` | string, max:50 |
| `empty_state` | string, max:255 |

---

## 3. Outros Endpoints Existentes ✅

### Actions (CRUD)

```
PUT    /admin/modules/{moduleId}/actions/{actionId}  ← Editar ação
POST   /admin/modules/{moduleId}/actions             ← Criar ação
DELETE /admin/modules/{moduleId}/actions/{actionId}  ← Deletar ação
```

### Transitions

```
GET    /admin/modules/{moduleId}/transitions         ← Buscar matriz
PUT    /admin/modules/{moduleId}/transitions         ← Atualizar matriz
```

### Audit Log ✅ JÁ EXISTE!

```
GET /admin/modules/{moduleId}/audit-log
```

#### Response

```json
{
  "data": [
    {
      "id": 123,
      "action": "texts_updated",
      "changed_data": { "menu_label": "Novo Label" },
      "user": { "id": 11, "name": "Super Admin" },
      "created_at": "2026-01-16T14:30:00Z"
    }
  ]
}
```

---

## 4. Sugestões de UX/UI - Respostas

### 🎨 Tooltips Dinâmicos ✅ VIÁVEL

Posso adicionar `tooltip` e `help_text` em cada status. 

**Proposta de alteração no endpoint `/modules/{id}/full`**:

```json
{
  "statuses": {
    "1": {
      "name": "solicitado",
      "label": "Solicitado",
      "description": "Pedido aguardando processamento",
      "tooltip": "O pedido acabou de ser criado",      // ← NOVO
      "help_text": "Atribuído automaticamente"         // ← NOVO
    }
  }
}
```

> **Deseja que eu implemente?** Estimado: ~30 min

---

### 🎨 Validation Rules ✅ VIÁVEL

Posso criar um endpoint de metadados:

```
GET /admin/modules/{moduleId}/schema
```

**Response:**

```json
{
  "texts": {
    "menu_label": { "type": "string", "required": false, "min": 1, "max": 100 },
    "page_title": { "type": "string", "required": false, "min": 1, "max": 100 },
    "page_description": { "type": "string", "required": false, "max": 500 }
  },
  "status_fields": {
    "label": { "type": "string", "required": true, "min": 3, "max": 50 },
    "color": { "type": "string", "pattern": "^(blue|red|yellow|green|purple|gray|orange)$" },
    "icon": { "type": "enum", "allowed": ["FileCheck", "Truck", "Store", "Bell", "..."] },
    "badge_variant": { "type": "enum", "allowed": ["default", "destructive", "outline", "secondary", "success", "warning"] }
  },
  "action_fields": {
    "label": { "type": "string", "required": true, "max": 50 },
    "icon": { "type": "enum", "allowed": ["Plus", "Edit", "Trash", "..."] },
    "confirm": { "type": "boolean" }
  }
}
```

> **Deseja que eu implemente?** Estimado: ~1 hora

---

### 🎨 Preview de Impacto ✅ VIÁVEL

Posso criar:

```
POST /admin/modules/{moduleId}/preview-impact
```

**Request:**
```json
{
  "action": "delete_status",
  "status_key": "3"
}
```

**Response:**
```json
{
  "can_proceed": false,
  "affected_records": 42,
  "warnings": [
    "42 pedidos estão no status 'Disponível na Loja'",
    "Transições de 1→3 e 2→3 serão removidas"
  ],
  "suggestions": [
    "Mova os pedidos para outro status antes de deletar"
  ]
}
```

> **Deseja que eu implemente?** Estimado: ~1.5 horas

---

## 📋 Resumo de Endpoints de Módulos

### Existentes ✅

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/modules` | Listar módulos |
| GET | `/modules/{id}` | Detalhes básicos |
| GET | `/modules/{id}/full` | Configuração completa |
| POST | `/modules/{id}/install` | Instalar módulo |
| POST | `/modules/{id}/activate` | Ativar globalmente |
| POST | `/modules/{id}/deactivate` | Desativar globalmente |
| GET | `/modules/{id}/stores` | Listar lojas |
| POST | `/modules/{id}/stores/{storeId}/activate` | Ativar para loja |
| POST | `/modules/{id}/stores/{storeId}/deactivate` | Desativar para loja |
| GET | `/modules/{id}/transitions` | Ver matriz de transições |
| PUT | `/modules/{id}/transitions` | Editar matriz |
| PUT | `/modules/{id}/texts` | Editar textos |
| PUT | `/modules/{id}/actions/{action}` | Editar ação |
| POST | `/modules/{id}/actions` | Criar ação |
| DELETE | `/modules/{id}/actions/{action}` | Deletar ação |
| GET | `/modules/{id}/audit-log` | Ver histórico |

### Propostos (Se necessário) ⏳

| Método | Endpoint | Descrição | Tempo |
|--------|----------|-----------|-------|
| PATCH | `/modules/{id}/statuses/{key}` | Editar status | 1h |
| POST | `/modules/{id}/statuses` | Criar status | 30min |
| DELETE | `/modules/{id}/statuses/{key}` | Deletar status | 30min |
| GET | `/modules/{id}/schema` | Regras de validação | 1h |
| POST | `/modules/{id}/preview-impact` | Preview impacto | 1.5h |

---

## 🚀 Service Sugerido para Frontend

```typescript
// api/modules.service.ts

// ✅ Já existentes
export async function getModule(id: string): Promise<ModuleDetail> {
  const { data } = await api.get(`/admin/modules/${id}`);
  return data.data;
}

export async function getModuleFull(id: string): Promise<ModuleFullConfig> {
  const { data } = await api.get(`/admin/modules/${id}/full`);
  return data.data;
}

export async function updateModuleTexts(id: string, texts: ModuleTexts): Promise<ModuleTexts> {
  const { data } = await api.put(`/admin/modules/${id}/texts`, { texts });
  return data.data;
}

export async function updateModuleAction(id: string, actionId: string, action: ActionConfig): Promise<void> {
  await api.put(`/admin/modules/${id}/actions/${actionId}`, { action });
}

export async function createModuleAction(id: string, action: ActionConfig): Promise<ActionConfig> {
  const { data } = await api.post(`/admin/modules/${id}/actions`, action);
  return data.data;
}

export async function deleteModuleAction(id: string, actionId: string): Promise<void> {
  await api.delete(`/admin/modules/${id}/actions/${actionId}`);
}

export async function getModuleAuditLog(id: string): Promise<AuditEntry[]> {
  const { data } = await api.get(`/admin/modules/${id}/audit-log`);
  return data.data;
}
```

---

## 💡 Recomendação do Backend

### Prioridade de Implementação

1. **Alta**: ✅ Textos e Actions já funcionam - podem usar agora
2. **Média**: Schema de validação - útil para UX
3. **Baixa**: Edição de status - impacto estrutural alto

### ⚠️ Alerta sobre Edição de Status

Editar/criar/deletar status tem impacto em:
- Transições existentes
- Registros no banco de dados
- Regras de permissão
- Automações configuradas

**Recomendo** implementar o Preview de Impacto **antes** dos endpoints de status.

---

## 🎯 Próximos Passos

Respondam quais funcionalidades devem ser priorizadas:

- [ ] Endpoints de Status (CRUD)
- [ ] Schema de validação
- [ ] Preview de impacto
- [ ] Tooltips dinâmicos

Ficarei no aguardo!

---

*Backend Team - MaisCapinhas - 16/01/2026 14:55*
