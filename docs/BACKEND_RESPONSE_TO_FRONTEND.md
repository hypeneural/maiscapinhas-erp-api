# 📬 Respostas do Backend para o Frontend

> **De:** Backend Team  
> **Para:** Frontend Team  
> **Data:** 16/01/2026 14:15  
> **Assunto:** RE: Clarificação de Endpoints e Formatos de Resposta

---

## ✅ Checklist de Respostas Completo

| # | Pergunta | Resposta |
|---|----------|----------|
| 1 | `/permissions/by-type` existe? | ✅ **SIM, EXISTE!** |
| 2 | Se sim, qual formato? A, B ou C? | **Formato A** (com pequena diferença - veja abaixo) |
| 3 | Formato de `/permissions/grouped` confirmado? | ✅ **CONFIRMADO** |
| 4 | `/modules/{id}/stores` path correto? | ✅ **CORRETO**: `GET /admin/modules/{id}/stores` |
| 5 | Graph API query params funcionam? | ✅ **SIM, TODOS!** |
| 6 | Endpoints de ativar/desativar existem? | ✅ **SIM, EXISTEM!** |

---

## 1. `GET /admin/permissions/by-type` ✅ EXISTE E FUNCIONA!

### Problema Identificado no Frontend

O erro `data.abilities is not iterable` ocorre porque a estrutura mudou ligeiramente:

```typescript
// ❌ O FRONTEND ESPERA:
data.abilities  // diretamente um array

// ✅ O BACKEND RETORNA:
data.abilities.permissions  // array está dentro de "permissions"
```

### Estrutura Real (Testada Agora)

```json
{
  "data": {
    "abilities": {
      "type": "ability",
      "display": "Ações",
      "description": "Permissões para executar ações específicas",
      "permissions": [
        { "id": 90, "name": "admin.audit.view", "display_name": "Ver logs", "type": "ability", "module": "admin" }
      ]
    },
    "screens": {
      "type": "screen",
      "display": "Telas",
      "description": "Permissões de acesso a telas/menus",
      "permissions": [
        { "id": 15, "name": "screen.pedidos", "display_name": "Menu Pedidos", "type": "screen", "module": "pedidos" }
      ]
    },
    "features": {
      "type": "feature",
      "display": "Features",
      "description": "Funcionalidades especiais do sistema",
      "permissions": [
        { "id": 112, "name": "feature.whatsapp-notifications", "display_name": "Enviar WhatsApp", "type": "feature" }
      ]
    }
  }
}
```

### 🔧 Correção no Frontend

```typescript
// ❌ CÓDIGO ANTIGO (quebrado)
const abilities = data.abilities; // TypeError: not iterable
abilities.map(p => p.name);

// ✅ CÓDIGO CORRIGIDO
interface PermissionsByTypeResponse {
  data: {
    abilities: PermissionTypeGroup;
    screens: PermissionTypeGroup;
    features: PermissionTypeGroup;
  };
}

interface PermissionTypeGroup {
  type: string;
  display: string;
  description: string;
  permissions: Permission[];
}

// Uso correto:
const { data } = response as PermissionsByTypeResponse;
const abilities = data.abilities.permissions;  // ← CORREÇÃO!
const screens = data.screens.permissions;      // ← CORREÇÃO!
const features = data.features.permissions;    // ← CORREÇÃO!
```

### Exemplo de Uso Corrigido

```typescript
// Listar todas as habilidades por módulo
const abilitiesByModule = data.abilities.permissions.reduce((acc, perm) => {
  if (!acc[perm.module]) acc[perm.module] = [];
  acc[perm.module].push(perm);
  return acc;
}, {} as Record<string, Permission[]>);
```

---

## 2. `GET /admin/permissions/grouped` ✅ CONFIRMADO

### Formato Confirmado

```typescript
interface ModuleGroup {
  module: string;              // "pedidos"
  module_display: string;      // "Pedidos"
  abilities: Permission[];     // ✅ SEMPRE presente, pode ser []
  screens: Permission[];       // ✅ SEMPRE presente, pode ser []
  features: Permission[];      // ✅ SEMPRE presente, pode ser []
}

// Response: { data: ModuleGroup[] }
```

✅ **Confirmado**: Todos os módulos SEMPRE terão os 3 arrays, mesmo que vazios.

---

## 3. `GET /admin/modules/{id}/stores` ✅ CRIADO E FUNCIONANDO!

### Path Correto

```
GET /api/v1/admin/modules/{moduleId}/stores
```

### Resposta Real (Testada)

```json
{
  "module_id": "pedidos-simples",
  "module_name": "Pedidos Simples",
  "stores": [
    {
      "store_id": 1,
      "store_name": "Komprão Centro",
      "city": "Tijucas",
      "is_active": false,
      "activated_at": null,
      "config": []
    },
    {
      "store_id": 2,
      "store_name": "Komprão Morretes",
      "city": "Itapema",
      "is_active": true,
      "activated_at": "2026-01-15T10:00:00Z",
      "config": { "max_items": 50 }
    }
  ],
  "total": 14,
  "active_count": 1
}
```

### Service Sugerido para Frontend

```typescript
// api/modules.service.ts
export async function getModuleStores(moduleId: string): Promise<ModuleStoresResponse> {
  const { data } = await api.get(`/admin/modules/${moduleId}/stores`);
  return data;
}

export async function activateModuleForStore(moduleId: string, storeId: number): Promise<void> {
  await api.post(`/admin/modules/${moduleId}/stores/${storeId}/activate`);
}

export async function deactivateModuleForStore(moduleId: string, storeId: number): Promise<void> {
  await api.post(`/admin/modules/${moduleId}/stores/${storeId}/deactivate`);
}
```

---

## 4. Graph API ✅ TODOS FUNCIONANDO COM PARAMS!

### Endpoints Testados

| Endpoint | Status | Query Params |
|----------|--------|--------------|
| `GET /admin/graph/overview` | ✅ OK | `depth`, `include_users` |
| `GET /admin/graph/role/{name}` | ✅ OK | `include_users`, `include_permissions` |
| `GET /admin/graph/user/{id}` | ✅ OK | `include_inherited` |
| `GET /admin/graph/store/{id}` | ✅ OK | `include_users` |
| `GET /admin/graph/module/{id}` | ✅ OK | - |

### Exemplos Testados

```bash
# ✅ TESTADO E FUNCIONANDO:
GET /admin/graph/overview?depth=3&include_users=true
GET /admin/graph/role/vendedor?include_users=true&include_permissions=true
GET /admin/graph/user/11?include_inherited=true
```

### Confirmações

| Pergunta | Resposta |
|----------|----------|
| Query params funcionam? | ✅ **SIM!** |
| `position: { x: 0, y: 0 }` nos nodes? | ✅ **SIM, SEMPRE!** |
| Nodes têm `data.icon`? | ✅ **SIM!** (Lucide icon names) |

### Resposta Real com include_users=true

```json
{
  "nodes": [
    {
      "id": "role-vendedor",
      "type": "role",
      "data": {
        "label": "Vendedor",
        "icon": "Shield",
        "level": 40,
        "permissions_count": 36,
        "users_count": 0
      },
      "position": { "x": 0, "y": 0 }
    },
    {
      "id": "user-42",
      "type": "user",
      "data": {
        "label": "Anderson Vendedor",
        "icon": "User",
        "email": "anderson@loja.com",
        "has_overrides": false
      },
      "position": { "x": 0, "y": 0 }
    }
  ],
  "edges": [
    { "id": "e1", "source": "role-vendedor", "target": "user-42", "type": "has_user" }
  ]
}
```

---

## 5. Endpoints de Ativar/Desativar Módulo ✅ EXISTEM!

```
POST /api/v1/admin/modules/{moduleId}/stores/{storeId}/activate
POST /api/v1/admin/modules/{moduleId}/stores/{storeId}/deactivate
```

### Request/Response

```typescript
// Ativar módulo para loja
POST /admin/modules/pedidos-simples/stores/1/activate
Response: { "message": "Módulo ativado para loja #1." }

// Desativar módulo para loja
POST /admin/modules/pedidos-simples/stores/1/deactivate
Response: { "message": "Módulo desativado para loja #1." }
```

---

## 6. Aprovação das Propostas de UX/UI

### 🎨 Página de Permissões
**Status:** ✅ **APROVADO!**

O design proposto está perfeito e alinhado com a estrutura da API.

**Sugestão adicional:** Adicionar um badge com a contagem de permissões por tipo:

```
📦 Pedidos (15)
   ⚡ Habilidades (10)
   🖥️ Telas (5)
```

### 🎨 Página de Módulos - Tab Lojas
**Status:** ✅ **APROVADO!**

O design e endpoints necessários existem:
- ✅ `GET /admin/modules/{id}/stores` 
- ✅ `POST /admin/modules/{id}/stores/{storeId}/activate`
- ✅ `POST /admin/modules/{id}/stores/{storeId}/deactivate`

---

## 📋 TypeScript Atualizado

### Arquivo: `types/permissions.ts`

```typescript
// ================================
// /permissions/by-type
// ================================

export interface PermissionTypeGroup {
  type: 'ability' | 'screen' | 'feature';
  display: string;
  description: string;
  permissions: Permission[];
}

export interface PermissionsByTypeResponse {
  data: {
    abilities: PermissionTypeGroup;
    screens: PermissionTypeGroup;
    features: PermissionTypeGroup;
  };
}

// ================================
// /permissions/grouped
// ================================

export interface ModuleGroup {
  module: string;
  module_display: string;
  abilities: Permission[];
  screens: Permission[];
  features: Permission[];
}

export interface PermissionsGroupedResponse {
  data: ModuleGroup[];
}

// ================================
// /modules/{id}/stores
// ================================

export interface ModuleStoreStatus {
  store_id: number;
  store_name: string;
  city: string;
  is_active: boolean;
  activated_at: string | null;
  config: Record<string, unknown>;
}

export interface ModuleStoresResponse {
  module_id: string;
  module_name: string;
  stores: ModuleStoreStatus[];
  total: number;
  active_count: number;
}
```

---

## 🚀 Próximos Passos do Frontend

1. **✅ Corrigir Permissions.tsx** 
   - Mudar `data.abilities` → `data.abilities.permissions`
   
2. **✅ Implementar tab "Lojas"**
   - Usar `getModuleStores(moduleId)`
   - Toggle calls `activateModuleForStore` / `deactivateModuleForStore`
   
3. **✅ Testar Graph API**
   - Todos endpoints funcionando
   - Layout com Dagre pode usar `position: {x: 0, y: 0}` como input inicial

---

## 💬 Dúvidas?

Se tiverem mais perguntas, me avisem! 

*Backend Team - MaisCapinhas - 16/01/2026 14:15*
