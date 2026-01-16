# 📬 Respostas - Configurações Avançadas de Módulos

> **De:** Backend Team  
> **Para:** Frontend Team  
> **Data:** 16/01/2026 15:40  
> **Status:** ✅ TODOS ENDPOINTS IMPLEMENTADOS!

---

## ✅ Checklist de Respostas

| # | Pergunta | Resposta |
|---|----------|----------|
| 1 | Quais configs existem? | ✅ Ver schema dinâmico abaixo |
| 2 | GET/PATCH config existe? | ✅ **SIM** - Implementado! |
| 3 | Configs globais ou por loja? | ✅ **AMBOS** - Global + por loja |
| 4 | Config de integração? | ✅ Dentro das seções do schema |
| 5 | Schema dinâmico? | ✅ **SIM** - Backend define, frontend renderiza |
| 6 | Cada módulo tem configs próprias? | ✅ **SIM** - Via `getConfigSchema()` |
| 7 | Valores padrão existem? | ✅ **SIM** - Via `defaults` no schema |

---

## 📋 Endpoints de Configuração

### Base URL: `/api/v1/admin/modules`

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/{id}/config` | Buscar config global com schema |
| PATCH | `/{id}/config` | Atualizar config global |
| POST | `/{id}/config/reset` | Restaurar para padrões |
| GET | `/{id}/stores/{storeId}/config` | Config por loja |
| PATCH | `/{id}/stores/{storeId}/config` | Atualizar config da loja |

---

## 1. GET `/modules/{id}/config` - Testado ✅

**Response Real:**

```json
{
  "module_id": "pedidos-simples",
  "module_name": "Pedidos Simples",
  "config": {
    "notify_on_status_change": false,
    "notification_channel": "whatsapp",
    "warning_after_days": 5,
    "auto_cancel_days": 20,
    "require_customer_phone": true,
    "require_notes": false
  },
  "schema": {
    "sections": {
      "notifications": {
        "label": "Notificações",
        "icon": "Bell",
        "description": "Configurações de notificação ao cliente",
        "fields": {
          "notify_on_status_change": {
            "type": "switch",
            "label": "Notificar ao mudar status",
            "hint": "Enviar notificação WhatsApp quando o status mudar",
            "default": false
          },
          "notification_channel": {
            "type": "select",
            "label": "Canal de notificação",
            "options": {
              "whatsapp": "WhatsApp",
              "email": "E-mail",
              "both": "Ambos"
            },
            "default": "whatsapp",
            "depends_on": "notify_on_status_change"
          }
        }
      },
      "deadlines": {
        "label": "Prazos",
        "icon": "Clock",
        "description": "Alertas e prazos automáticos",
        "fields": {
          "warning_after_days": {
            "type": "number",
            "label": "Alertar após X dias parado",
            "hint": "Número de dias sem movimentação para exibir alerta",
            "min": 1,
            "max": 60,
            "default": 5
          },
          "auto_cancel_days": {
            "type": "number",
            "label": "Cancelar automaticamente após X dias",
            "hint": "Dias para cancelamento automático (0 = desativado)",
            "min": 0,
            "max": 90,
            "default": 20
          }
        }
      },
      "requirements": {
        "label": "Requisitos",
        "icon": "CheckSquare",
        "description": "Campos obrigatórios e validações",
        "fields": {
          "require_customer_phone": {
            "type": "switch",
            "label": "Exigir telefone do cliente",
            "hint": "Telefone será obrigatório para criar registro",
            "default": true
          },
          "require_notes": {
            "type": "switch",
            "label": "Exigir observações",
            "hint": "Campo de observações será obrigatório",
            "default": false
          }
        }
      }
    },
    "defaults": { ... }
  },
  "has_custom_config": false
}
```

---

## 2. PATCH `/modules/{id}/config` - Atualizar

```http
PATCH /api/v1/admin/modules/pedidos-simples/config
Authorization: Bearer {token}
Content-Type: application/json

{
  "notify_on_status_change": true,
  "warning_after_days": 3,
  "require_customer_phone": false
}
```

**Response:**
```json
{
  "message": "Configurações atualizadas.",
  "config": {
    "notify_on_status_change": true,
    "notification_channel": "whatsapp",
    "warning_after_days": 3,
    "auto_cancel_days": 20,
    "require_customer_phone": false,
    "require_notes": false
  }
}
```

---

## 3. POST `/modules/{id}/config/reset` - Restaurar Padrões

```http
POST /api/v1/admin/modules/pedidos-simples/config/reset
Authorization: Bearer {token}
```

**Response:**
```json
{
  "message": "Configurações restauradas para os valores padrão.",
  "config": {
    "notify_on_status_change": false,
    "notification_channel": "whatsapp",
    "warning_after_days": 5,
    "auto_cancel_days": 20,
    "require_customer_phone": true,
    "require_notes": false
  }
}
```

---

## 4. Config por Loja

### GET `/modules/{id}/stores/{storeId}/config`

```json
{
  "module_id": "pedidos-simples",
  "store_id": 1,
  "global_config": { ... },
  "store_config": {
    "warning_after_days": 2
  },
  "effective_config": {
    "notify_on_status_change": false,
    "warning_after_days": 2  // ← Sobrescrito pela loja
  },
  "schema": { ... }
}
```

### PATCH `/modules/{id}/stores/{storeId}/config`

```json
{
  "warning_after_days": 2,
  "require_notes": true
}
```

---

## 📋 Tipos de Campo Suportados

| Tipo | Componente UI | Validação |
|------|---------------|-----------|
| `switch` | Toggle/Switch | boolean |
| `number` | Input number | min, max |
| `select` | Select/Dropdown | in:options |
| `text` | Input text | max length |
| `textarea` | Textarea | max length |

---

## 📋 TypeScript Interfaces

```typescript
// ================================
// Config Schema
// ================================

interface ConfigField {
  type: 'switch' | 'number' | 'select' | 'text' | 'textarea';
  label: string;
  hint?: string;
  required?: boolean;
  default?: unknown;
  min?: number;
  max?: number;
  options?: Record<string, string>;
  depends_on?: string;  // Mostrar apenas se outro campo for true
}

interface ConfigSection {
  label: string;
  icon: string;
  description?: string;
  fields: Record<string, ConfigField>;
}

interface ConfigSchema {
  sections: Record<string, ConfigSection>;
  defaults: Record<string, unknown>;
}

// ================================
// API Responses
// ================================

interface ModuleConfigResponse {
  module_id: string;
  module_name: string;
  config: Record<string, unknown>;
  schema: ConfigSchema;
  has_custom_config: boolean;
}

interface StoreConfigResponse {
  module_id: string;
  store_id: number;
  global_config: Record<string, unknown>;
  store_config: Record<string, unknown>;
  effective_config: Record<string, unknown>;
  schema: ConfigSchema;
}
```

---

## 🎨 Renderização Dinâmica de UI

### Exemplo de Componente React

```tsx
function DynamicConfigForm({ 
  schema, 
  config, 
  onChange 
}: { 
  schema: ConfigSchema; 
  config: Record<string, unknown>;
  onChange: (key: string, value: unknown) => void;
}) {
  return (
    <div className="space-y-8">
      {Object.entries(schema.sections).map(([sectionKey, section]) => (
        <Card key={sectionKey}>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <DynamicIcon name={section.icon} />
              {section.label}
            </CardTitle>
            {section.description && (
              <CardDescription>{section.description}</CardDescription>
            )}
          </CardHeader>
          <CardContent className="space-y-4">
            {Object.entries(section.fields).map(([fieldKey, field]) => {
              // Verificar dependência
              if (field.depends_on && !config[field.depends_on]) {
                return null;
              }

              return (
                <div key={fieldKey} className="flex items-center justify-between">
                  <div>
                    <Label>{field.label}</Label>
                    {field.hint && (
                      <p className="text-sm text-muted-foreground">{field.hint}</p>
                    )}
                  </div>
                  <ConfigFieldInput
                    type={field.type}
                    value={config[fieldKey]}
                    options={field.options}
                    min={field.min}
                    max={field.max}
                    onChange={(value) => onChange(fieldKey, value)}
                  />
                </div>
              );
            })}
          </CardContent>
        </Card>
      ))}
    </div>
  );
}

function ConfigFieldInput({ type, value, options, min, max, onChange }) {
  switch (type) {
    case 'switch':
      return <Switch checked={value} onCheckedChange={onChange} />;
    case 'number':
      return (
        <Input 
          type="number" 
          value={value} 
          min={min} 
          max={max}
          onChange={(e) => onChange(parseInt(e.target.value))} 
        />
      );
    case 'select':
      return (
        <Select value={value} onValueChange={onChange}>
          <SelectTrigger><SelectValue /></SelectTrigger>
          <SelectContent>
            {Object.entries(options).map(([key, label]) => (
              <SelectItem key={key} value={key}>{label}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      );
    default:
      return <Input value={value} onChange={(e) => onChange(e.target.value)} />;
  }
}
```

---

## 🎨 UI Sugerida

```
┌─────────────────────────────────────────────────────────────────┐
│ ⚙️ Configurações Avançadas - Pedidos Simples                    │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ 🔔 NOTIFICAÇÕES                                                 │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Notificar ao mudar status                    [✓]            │ │
│ │ ℹ️ Enviar notificação WhatsApp quando status mudar          │ │
│ │                                                             │ │
│ │ Canal de notificação                         [WhatsApp ▼]   │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ ⏱️ PRAZOS                                                       │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Alertar após X dias parado                   [5    ] dias   │ │
│ │ ℹ️ Número de dias sem movimentação para exibir alerta       │ │
│ │                                                             │ │
│ │ Cancelar automaticamente após                [20   ] dias   │ │
│ │ ℹ️ Dias para cancelamento automático (0 = desativado)       │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ ✅ REQUISITOS                                                   │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Exigir telefone do cliente                   [✓]            │ │
│ │ Exigir observações                           [ ]            │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│               [🔄 Restaurar Padrões]  [💾 Salvar Alterações]    │
└─────────────────────────────────────────────────────────────────┘
```

---

## 🚀 Service Layer Sugerido

```typescript
// services/module-config.service.ts

export const getModuleConfig = (moduleId: string) =>
  api.get<ModuleConfigResponse>(`/admin/modules/${moduleId}/config`);

export const updateModuleConfig = (moduleId: string, config: Record<string, unknown>) =>
  api.patch(`/admin/modules/${moduleId}/config`, config);

export const resetModuleConfig = (moduleId: string) =>
  api.post(`/admin/modules/${moduleId}/config/reset`);

export const getStoreConfig = (moduleId: string, storeId: number) =>
  api.get<StoreConfigResponse>(`/admin/modules/${moduleId}/stores/${storeId}/config`);

export const updateStoreConfig = (moduleId: string, storeId: number, config: Record<string, unknown>) =>
  api.patch(`/admin/modules/${moduleId}/stores/${storeId}/config`, config);
```

---

## 📝 Auditoria

Todas as alterações são registradas no audit log:

```json
{
  "action": "config_updated",
  "data": {
    "changes": { "warning_after_days": 3 },
    "previous": { "warning_after_days": 5 }
  },
  "user_name": "Super Admin",
  "timestamp": "2026-01-16T15:40:00Z"
}
```

---

*Backend Team - MaisCapinhas - 16/01/2026 15:40*
