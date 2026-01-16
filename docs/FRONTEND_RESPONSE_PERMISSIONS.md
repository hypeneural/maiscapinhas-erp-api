# 📬 Respostas do Backend - Sistema de Permissões v2.0

> **De:** Backend  
> **Para:** Time Frontend  
> **Data:** 16/01/2026  
> **Status:** ✅ IMPLEMENTADO

---

## ✅ Tudo Pronto!

Implementamos TODAS as sugestões e confirmações solicitadas.

---

## 📊 Resumo de Implementação

| Item | Status | Endpoint |
|------|--------|----------|
| `/me` com permissions | ✅ Pronto | `GET /api/v1/me` |
| Permissões agrupadas | ✅ Pronto | `GET /admin/permissions?group_by=module` |
| Clone de Role | ✅ Pronto | `POST /admin/roles/{id}/clone` |
| Preview de Mudanças | ✅ Pronto | `POST /admin/permissions/preview` |
| Copiar Permissões | ✅ Pronto | `POST /admin/users/{id}/permissions/copy-from/{source}` |
| Bulk Grant | ✅ Pronto | `POST /admin/permissions/bulk-grant` |
| Audit Log Usuário | ✅ Pronto | `GET /admin/users/{id}/permissions/audit-log` |
| Edit Permissions Role | ✅ Pronto | `PUT /admin/roles/{id}/permissions` |

---

## 📋 Formato do `/me` (ATUALIZADO)

```json
{
  "data": {
    "user": {
      "id": 1,
      "name": "João Vendedor",
      "email": "joao@loja.com",
      "active": true,
      "is_super_admin": false,
      "is_global_admin": false,
      "has_fabrica_access": false,
      "roles": ["vendedor"],
      "whatsapp": "48999999999",
      "avatar_url": "https://...",
      "birth_date": "1990-05-15",
      "hire_date": "2022-01-09",
      "created_at": "2026-01-01T00:00:00Z"
    },
    "stores": [
      { "id": 5, "name": "Loja Shopping", "city": "Tijucas", "role": "vendedor" }
    ],
    "permissions": [
      "pedidos.view",
      "pedidos.create",
      "screen.pedidos",
      "screen.dashboard"
    ],
    "temporary_permissions": [
      {
        "permission": "capas.view-global",
        "expires_at": "2026-01-20T23:59:59Z",
        "granted_by": "Admin Maria",
        "reason": "Cobertura de férias"
      }
    ],
    "expiring_soon": [
      {
        "permission": "capas.view-global",
        "expires_in_hours": 48,
        "expires_at": "2026-01-18T23:59:59Z"
      }
    ],
    "dashboard_layout": {
      "widgets": ["stats", "recent_orders", "notifications"]
    }
  }
}
```

---

## 📋 Permissões Agrupadas

`GET /admin/permissions?group_by=module`

```json
{
  "data": [
    {
      "module": "pedidos",
      "module_display": "Pedidos",
      "count": 12,
      "permissions": [
        { "id": 1, "name": "pedidos.view", "display_name": "Ver pedidos", "type": "ability" }
      ]
    }
  ],
  "grouped_by": "module"
}
```

Alternativa: `GET /admin/permissions/grouped`

---

## 📋 Preview de Mudanças

`POST /admin/permissions/preview`

```json
// Request
{
  "user_id": 1,
  "add_permissions": ["reports.view"],
  "remove_permissions": ["pedidos.delete"]
}

// Response
{
  "user_id": 1,
  "user_name": "João Vendedor",
  "current": ["pedidos.view", "pedidos.delete"],
  "after": ["pedidos.view", "reports.view"],
  "added": ["reports.view"],
  "removed": ["pedidos.delete"],
  "total_change": 2
}
```

---

## 📋 Copiar Permissões

`POST /admin/users/{targetId}/permissions/copy-from/{sourceId}`

```json
// Request (opcional)
{
  "include_temporary": false,
  "expires_at": "2026-02-01"
}

// Response
{
  "message": "Permissões copiadas de 'Maria Admin' para 'João Novo'.",
  "data": {
    "source_user": "Maria Admin",
    "target_user": "João Novo",
    "permissions_copied": ["capas.view-global", "reports.view"],
    "count": 2
  }
}
```

---

## 📋 Bulk Grant

`POST /admin/permissions/bulk-grant`

```json
// Request (max 10 usuários)
{
  "user_ids": [1, 2, 3],
  "permissions": ["reports.view", "exports.excel"],
  "expires_at": "2026-02-01T23:59:59Z",
  "reason": "Projeto especial Q1"
}

// Response
{
  "message": "Permissões concedidas com sucesso.",
  "data": [
    { "user_id": 1, "user_name": "João", "granted": ["reports.view", "exports.excel"] },
    { "user_id": 2, "user_name": "Maria", "granted": ["reports.view", "exports.excel"] }
  ],
  "total_users": 3,
  "total_permissions": 2
}
```

---

## 📋 Audit Log do Usuário

`GET /admin/users/{id}/permissions/audit-log`

```json
{
  "user_id": 1,
  "user_name": "João Vendedor",
  "entries": [
    {
      "permission": "capas.view-global",
      "type": "grant",
      "is_active": true,
      "granted_by": "Admin Maria",
      "reason": "Cobertura de férias",
      "expires_at": "2026-01-20T23:59:59Z",
      "created_at": "2026-01-15T10:00:00Z",
      "updated_at": "2026-01-15T10:00:00Z"
    }
  ],
  "total": 5
}
```

---

## 📋 Clone de Role

`POST /admin/roles/{id}/clone`

```json
// Request
{
  "name": "conferente-senior",
  "display_name": "Conferente Sênior",
  "description": "Conferente com acesso a relatórios"
}

// Response
{
  "message": "Role clonada de 'Conferente'.",
  "data": {
    "id": 15,
    "name": "conferente-senior",
    "display_name": "Conferente Sênior",
    "permissions_count": 12,
    "cloned_from": "conferente"
  }
}
```

---

## 📋 Edit Permissions de Role

`PUT /admin/roles/{id}/permissions`

```json
// Request
{
  "add": ["reports.view", "exports.excel"],
  "remove": ["pedidos.delete"]
}

// Response
{
  "message": "Permissões atualizadas.",
  "data": {
    "role_id": 3,
    "permissions": ["pedidos.view", "pedidos.create", "reports.view", "exports.excel"],
    "permissions_count": 15
  }
}
```

---

## 📋 Overrides por Loja

```json
{
  "overrides": [
    {
      "permission": "capas.view-global",
      "type": "grant",
      "store_id": null,         // ← Global
      "expires_at": "2026-01-20"
    },
    {
      "permission": "pedidos.export",
      "type": "grant",
      "store_id": 5,            // ← Apenas loja #5
      "expires_at": null
    }
  ]
}
```

---

## 🚀 Endpoints Finais

### Permissões
| Método | Endpoint | Novo? |
|--------|----------|-------|
| GET | `/admin/permissions` | |
| GET | `/admin/permissions/grouped` | |
| GET | `/admin/permissions/by-type` | |
| POST | `/admin/permissions/preview` | ✅ |
| POST | `/admin/permissions/bulk-grant` | ✅ |

### Usuários + Permissões
| Método | Endpoint | Novo? |
|--------|----------|-------|
| POST | `/admin/users/{id}/permissions/copy-from/{source}` | ✅ |
| GET | `/admin/users/{id}/permissions/audit-log` | ✅ |
| GET | `/admin/users/{id}/permission-overrides/effective` | |

### Roles
| Método | Endpoint | Novo? |
|--------|----------|-------|
| GET | `/admin/roles` | |
| GET | `/admin/roles/{id}` | |
| POST | `/admin/roles` | |
| PUT | `/admin/roles/{id}` | |
| DELETE | `/admin/roles/{id}` | |
| POST | `/admin/roles/{id}/clone` | ✅ |
| PUT | `/admin/roles/{id}/permissions` | ✅ |

---

## ✅ Podem Começar!

Todos os endpoints estão implementados e prontos para uso.

---

*Backend Team - MaisCapinhas*
