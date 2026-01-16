# 📬 Respostas - CRUD Usuários Completo

> **De:** Backend Team  
> **Para:** Frontend Team  
> **Data:** 16/01/2026 19:35  
> **Status:** ✅ TODAS AS RESPOSTAS

---

## ✅ Checklist de Respostas

| # | Pergunta | Resposta |
|---|----------|----------|
| 1.1 | Endpoints CRUD corretos? | ✅ SIM |
| 1.2 | Filtros suportados? | ✅ search, active, per_page |
| 1.3 | Campos obrigatórios? | ✅ name, email, password |
| 2.1 | Separação roles? | ✅ Global via `user_store_roles` |
| 2.2 | GET /roles existe? | ✅ `/admin/roles/available` |
| 3.1 | Endpoints de vínculo? | ✅ bulk add/update/remove |
| 3.4 | PUT /stores faz replace? | ✅ SIM (syncStores) |
| 4.2 | Modelo permissões? | ✅ Role + Override |
| 4.3 | /permissions/grouped? | ✅ SIM, todas as permissões |
| 5.2 | POST /stores/{id}/users? | ✅ SIM |
| 6.1 | Hierarquia? | ✅ Via `level` em roles |

---

## 1️⃣ CRUD DE USUÁRIOS

### Endpoints (22 rotas)

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/admin/users` | Listar usuários |
| POST | `/admin/users` | Criar usuário |
| GET | `/admin/users/{id}` | Detalhes |
| PUT/PATCH | `/admin/users/{id}` | Atualizar |
| DELETE | `/admin/users/{id}` | Desativar (soft) |

### Filtros Suportados

```http
GET /admin/users?search=joao&active=true&per_page=15
```

| Filtro | Tipo | Descrição |
|--------|------|-----------|
| `search` | string | Busca nome/email |
| `active` | boolean | Status ativo |
| `per_page` | integer | Paginação (default: 15) |

### Campos Obrigatórios no POST

```json
{
  "name": "João",           // required
  "email": "joao@email.com", // required, unique
  "password": "senha123"     // required, min:8
}
```

### Campos Opcionais

```json
{
  "active": true,
  "whatsapp": "11999999999",
  "birth_date": "1990-01-15",
  "nickname": "Joãozinho",
  "stores": []  // Opcional - pode vincular depois
}
```

---

## 2️⃣ ROLES

### Roles são unificadas (todas via `user_store_roles`)

```
Role + store_id = NULL → Global
Role + store_id = X   → Específica da loja
```

**Exemplos:**
- `{role: "fabrica", store_id: null}` → Acesso global à fábrica
- `{role: "admin", store_id: 1}` → Admin apenas na loja 1

### Endpoint para listar roles

```http
GET /admin/roles/available
```

**Response:**
```json
{
  "data": [
    {"id": 1, "name": "super-admin", "display_name": "Super Admin", "level": 100},
    {"id": 2, "name": "admin", "display_name": "Administrador", "level": 80},
    {"id": 3, "name": "gerente", "display_name": "Gerente", "level": 60},
    {"id": 4, "name": "conferente", "display_name": "Conferente", "level": 40},
    {"id": 5, "name": "vendedor", "display_name": "Vendedor", "level": 20},
    {"id": 6, "name": "fabrica", "display_name": "Fábrica", "level": 50}
  ]
}
```

### Atribuir role ao usuário

```http
POST /admin/users/{id}/roles
```

```json
{
  "role_id": 6,       // ID da role
  "store_id": null    // null = global, ou ID da loja
}
```

### Sync roles (replace all)

```http
PUT /admin/users/{id}/roles/sync
```

```json
{
  "assignments": [
    {"role_id": 6, "store_id": null},
    {"role_id": 2, "store_id": 1}
  ]
}
```

---

## 3️⃣ VÍNCULO USUÁRIO ↔ LOJA

### Endpoints

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| PUT | `/admin/users/{id}/stores` | Sync (replace all) |
| POST | `/admin/users/{id}/stores/bulk` | Adicionar lojas |
| PATCH | `/admin/users/{id}/stores/bulk` | Atualizar role |
| DELETE | `/admin/users/{id}/stores/bulk` | Remover lojas |

### Bulk Add

```http
POST /admin/users/{id}/stores/bulk
```

```json
{
  "stores": [
    {"store_id": 1, "role": "admin"},
    {"store_id": 2, "role": "vendedor"}
  ]
}
```

### Sync (Replace All)

```http
PUT /admin/users/{id}/stores
```

```json
{
  "stores": [{"store_id": 1, "role": "admin"}]
}
```

**⚠️ SIM, isso remove lojas não listadas!**

### Bulk Update Role

```http
PATCH /admin/users/{id}/stores/bulk
```

```json
{
  "role": "gerente",
  "store_ids": [1, 2, 3]
}
```

### Bulk Remove

```http
DELETE /admin/users/{id}/stores/bulk
```

```json
{
  "store_ids": [2, 3]
}
```

---

## 4️⃣ PERMISSÕES

### Modelo de Permissões

```
Permissão Final = Role Permissions + User Overrides
```

**Prioridade (maior vence):**
1. User Override (grant/deny específico)
2. Role Permissions (via role atribuída)

### Tipos de Override

| Tipo | Efeito |
|------|--------|
| `grant` | Dá permissão mesmo sem role |
| `deny` | Remove permissão mesmo com role |

### Endpoints de Override

```http
GET    /admin/users/{id}/permission-overrides       # Listar
POST   /admin/users/{id}/permission-overrides       # Criar
POST   /admin/users/{id}/permission-overrides/bulk  # Bulk create
GET    /admin/users/{id}/permission-overrides/effective  # Ver final
DELETE /admin/users/{id}/permission-overrides/clear # Limpar todos
PUT    /admin/users/{id}/permission-overrides/{id}  # Atualizar
DELETE /admin/users/{id}/permission-overrides/{id}  # Remover
```

### Criar Override

```http
POST /admin/users/{id}/permission-overrides
```

```json
{
  "permission_id": 15,
  "type": "grant",          // ou "deny"
  "store_id": null,         // null = global
  "expires_at": "2026-02-01T00:00:00Z",  // opcional
  "reason": "Cobertura de férias"        // opcional
}
```

### Ver Permissões Efetivas

```http
GET /admin/users/{id}/permission-overrides/effective
```

**Response:**
```json
{
  "user_id": 5,
  "permissions": [
    {
      "permission": "pedidos.view",
      "has_access": true,
      "source": "role",
      "role_name": "vendedor"
    },
    {
      "permission": "pedidos.delete",
      "has_access": true,
      "source": "user_override",
      "expires_at": "2026-02-01"
    }
  ]
}
```

---

## 5️⃣ LOJAS

### Endpoints (16 rotas)

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/admin/stores` | Listar lojas |
| POST | `/admin/stores` | Criar loja |
| GET | `/admin/stores/{id}` | Detalhes |
| PUT | `/admin/stores/{id}` | Atualizar |
| DELETE | `/admin/stores/{id}` | Deletar |
| GET | `/admin/stores/{id}/users` | Listar usuários da loja |
| POST | `/admin/stores/{id}/users` | Vincular usuário |
| PUT | `/admin/stores/{id}/users/{user}` | Atualizar role |
| DELETE | `/admin/stores/{id}/users/{user}` | Desvincular |

### Vincular usuários à loja

```http
POST /admin/stores/{id}/users
```

```json
{
  "user_id": 5,
  "role": "vendedor"
}
```

---

## 6️⃣ HIERARQUIA

### Via campo `level` nas roles

| Role | Level |
|------|-------|
| super-admin | 100 |
| admin | 80 |
| gerente | 60 |
| fabrica | 50 |
| conferente | 40 |
| vendedor | 20 |

**Regra:** Usuário só pode atribuir roles com `level` menor que o seu.

### Quem pode fazer o quê

| Ação | Super Admin | Admin | Gerente |
|------|-------------|-------|---------|
| Criar usuário | ✅ | ✅* | ❌ |
| Editar usuário | ✅ | ✅* | ❌ |
| Vincular loja | ✅ | ✅* | ❌ |
| Override permissão | ✅ | ❌ | ❌ |

*Admin só pode gerenciar usuários das suas lojas

### Soft Delete

- **Usuários:** Desativados (`active = false`)
- **Lojas:** Soft delete (`deleted_at`)

---

## 📋 Audit Log

```http
GET /admin/users/{id}/permissions/audit-log
```

**Response:**
```json
{
  "data": [
    {
      "action": "permission_granted",
      "permission": "pedidos.delete",
      "performed_by": "Super Admin",
      "timestamp": "2026-01-16T19:00:00Z"
    }
  ]
}
```

---

*Backend Team - MaisCapinhas - 16/01/2026 19:35*
