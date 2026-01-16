# 📬 Respostas do Backend - Fase 2 (Módulos e UserForm)

> **De:** Backend  
> **Para:** Time Frontend  
> **Data:** 16/01/2026  
> **Status:** ✅ IMPLEMENTADO

---

## ✅ Todas as Perguntas Respondidas + Melhorias de UX Implementadas

---

## 📦 1. Módulos - Formato da Lista

`GET /admin/modules`

```json
{
  "data": [
    {
      "id": "pedidos-simples",
      "slug": "pedidos-simples",
      "name": "Pedidos Simples",
      "description": "Gestão de pedidos básicos",
      "version": "1.0.0",
      "icon": "ShoppingCart",
      "dependencies": [],
      "is_installed": true,
      "is_active": true,
      "is_core": true,
      "status": "active",
      "stores_count": 5,
      "status_count": 6,
      "permission_count": 12,
      "automation_count": 3
    }
  ],
  "total": 2
}
```

**Respostas:**
| Pergunta | Resposta |
|----------|----------|
| `icon` retorna o quê? | ✅ Nome do ícone Lucide (ex: "ShoppingCart") |
| Existe campo `status`? | ✅ Sim: "active", "inactive", "not_installed" |
| `stores_count` funciona? | ✅ Sim, conta lojas onde está ativo |

---

## 📦 2. Endpoint `/admin/modules/{id}/full`

**✅ 100% Implementado!** Retorna tudo que vocês precisam:

```json
{
  "data": {
    "id": "pedidos-simples",
    "name": "Pedidos Simples",
    "is_installed": true,
    "is_active": true,
    "texts": {
      "page_title": "Pedidos",
      "page_description": "Gestão de pedidos simples",
      "empty_state": "Nenhum pedido encontrado",
      "loading_title": "Carregando pedidos...",
      "error_title": "Erro ao carregar"
    },
    "statuses": { ... },
    "transitions": { ... },
    "transition_role_matrix": { ... },
    "actions": { ... },
    "filters": [ ... ],
    "table_columns": { ... },
    "bulk_actions": [ ... ],
    "row_actions": { ... },
    "notifications": { ... },
    "stats_cards": { ... },
    "conditional_fields": { ... },
    "permissions": [ ... ],
    "screens": [ ... ]
  }
}
```

---

## 📦 3. Ativação por Loja

**Usamos Opção A (Toggle simples):**

```
POST /admin/modules/{id}/stores/{storeId}/activate
POST /admin/modules/{id}/stores/{storeId}/deactivate
```

**Exemplo:**
```bash
POST /admin/modules/pedidos-simples/stores/5/activate
```

---

## 📦 4. Configurações por Loja

**Sim!** A tabela pivot `module_store` tem campo `config`:

```json
// GET /admin/modules/{id}/stores
{
  "stores": [
    {
      "store_id": 1,
      "store_name": "Loja Tijucas",
      "is_active": true,
      "config": {
        "max_items": 10
      }
    },
    {
      "store_id": 2,
      "store_name": "Loja Itapema",
      "is_active": true,
      "config": {
        "max_items": 50
      }
    }
  ]
}
```

Para editar config por loja, use PUT no activate:
```
POST /admin/modules/{id}/stores/{storeId}/activate
{ "config": { "max_items": 50 } }
```

---

## 📦 5. Módulos - Dependências

**Já implementado!** O campo `dependencies` mostra os módulos necessários:

```json
{
  "id": "capas-personalizadas",
  "dependencies": ["pedidos-simples"],
  ...
}
```

Se tentar ativar "capas-personalizadas" sem "pedidos-simples" ativo, retorna erro:
```json
{ "message": "Dependência 'pedidos-simples' não está ativa." }
```

---

## 👤 6. UserForm - Estrutura de Tabs

**Recomendação de tabs:**

```
[Dados Básicos] [Lojas & Roles] [Permissões] [Auditoria]
```

**Tab Permissões deve mostrar:**
1. ✅ Permissões efetivas (`GET /admin/users/{id}/permission-overrides/effective`)
2. ✅ Overrides ativos (`GET /admin/users/{id}/permission-overrides`)
3. ✅ Roles do usuário (`GET /admin/users/{id}/roles`)

**Editar overrides:** Pode ser direto no form ou modal - endpoint é o mesmo.

---

## 👤 7. Adicionar Override - Formato

**Confirmado!** O formato que vocês esperavam está correto:

```json
POST /admin/users/{id}/permission-overrides
{
  "permission": "capas.view-global",
  "type": "grant",
  "store_id": null,
  "expires_at": "2026-02-01T23:59:59Z",
  "reason": "Cobertura de férias"
}
```

| Campo | Obrigatório | Descrição |
|-------|-------------|-----------|
| `permission` | ✅ Sim | Nome da permissão |
| `type` | ✅ Sim | "grant" ou "deny" |
| `store_id` | ❌ Não | null = global, número = específico |
| `expires_at` | ❌ Não | ISO8601 ou null = permanente |
| `reason` | ❌ Não | Motivo da concessão |

---

## 👤 8. Roles por Loja vs Global

**Resposta: Por loja!**

A tabela `user_store_roles` vincula usuário + loja + role:

```json
// GET /admin/users/1/roles
{
  "roles": [
    { "role": "vendedor", "store_id": 1, "store_name": "Loja A" },
    { "role": "gerente", "store_id": 2, "store_name": "Loja B" }
  ]
}
```

**João pode ser:**
- Vendedor na Loja A
- Gerente na Loja B
- E ter override global de "capas.view-global"

---

## 🎨 UX Improvements Implementados

### 1. Indicadores no `/me`

```json
{
  "has_temporary_permissions": true,
  "temporary_count": 3,
  "expiring_count": 1,
  ...
}
```

**Uso:** Badge no header: `🔔 3 permissões temporárias`

---

### 2. Permissões Mais Concedidas

`GET /admin/permissions/most-granted?limit=10`

```json
{
  "data": [
    { "permission": "capas.view-global", "display_name": "Ver todas as capas", "module": "capas", "count": 15 },
    { "permission": "reports.view", "display_name": "Ver relatórios", "module": "reports", "count": 8 }
  ],
  "total": 10
}
```

**Uso:** Sugestões ao adicionar override.

---

### 3. Usuários por Permissão (Auditoria)

`GET /admin/permissions/pedidos.delete/users`

```json
{
  "permission": "pedidos.delete",
  "display_name": "Deletar pedidos",
  "users": [
    { "id": 1, "name": "Admin", "email": "admin@...", "source": "role", "is_temporary": false },
    { "id": 5, "name": "João", "email": "joao@...", "source": "override", "expires_at": "2026-02-01", "is_temporary": true }
  ],
  "total": 2
}
```

**Uso:** Auditoria: "Quem pode deletar pedidos?"

---

## 📋 Endpoints Prontos

### Módulos
| Método | Endpoint | Status |
|--------|----------|--------|
| GET | `/admin/modules` | ✅ |
| GET | `/admin/modules/{id}` | ✅ |
| GET | `/admin/modules/{id}/full` | ✅ |
| POST | `/admin/modules/{id}/activate` | ✅ |
| POST | `/admin/modules/{id}/deactivate` | ✅ |
| POST | `/admin/modules/{id}/stores/{store}/activate` | ✅ |
| POST | `/admin/modules/{id}/stores/{store}/deactivate` | ✅ |

### UserForm - Permissões
| Método | Endpoint | Status |
|--------|----------|--------|
| GET | `/admin/users/{id}/permission-overrides` | ✅ |
| GET | `/admin/users/{id}/permission-overrides/effective` | ✅ |
| POST | `/admin/users/{id}/permission-overrides` | ✅ |
| DELETE | `/admin/users/{id}/permission-overrides/{id}` | ✅ |

### UserForm - Roles
| Método | Endpoint | Status |
|--------|----------|--------|
| GET | `/admin/users/{id}/roles` | ✅ |
| POST | `/admin/users/{id}/roles` | ✅ |
| DELETE | `/admin/users/{id}/roles/{id}` | ✅ |

### Analytics (NOVOS)
| Método | Endpoint | Status |
|--------|----------|--------|
| GET | `/admin/permissions/most-granted` | ✅ NEW |
| GET | `/admin/permissions/{name}/users` | ✅ NEW |

---

## ❌ Não Implementado (Discutir)

| Sugestão | Status | Motivo |
|----------|--------|--------|
| Validate Removal | 🟡 Pendente | Complexo - precisa verificar tarefas em andamento |
| Module Previews | 🟡 Futuro | Precisamos criar screenshots primeiro |

---

## ✅ Podem Prosseguir!

Todos os endpoints necessários estão prontos.

---

*Backend Team - MaisCapinhas*
