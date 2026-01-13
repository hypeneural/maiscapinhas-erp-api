# Guia de Permissões para Frontend

> **Data**: 2026-01-13
> **Equipe**: Backend → Frontend

---

## Endpoint `/api/v1/me`

O endpoint `/me` agora retorna informações completas de permissões do usuário:

```json
{
  "data": {
    "user": {
      "id": 1,
      "name": "Admin Sistema",
      "email": "admin@maiscapinhas.com.br",
      "active": true,
      "is_super_admin": false,
      "is_global_admin": true,
      "has_fabrica_access": false,
      "roles": [],
      "whatsapp": "...",
      "avatar_url": "...",
      "instagram": "...",
      "birth_date": "1990-05-15",
      "hire_date": "2022-01-09",
      "created_at": "2026-01-01T00:00:00+00:00"
    },
    "stores": [
      { "id": 1, "name": "Loja Tijucas", "city": "Tijucas", "role": "admin" },
      { "id": 2, "name": "Loja Itapema", "city": "Itapema", "role": "vendedor" }
    ]
  }
}
```

---

## Campos de Permissão

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `is_super_admin` | boolean | Flag do banco. Super admin com acesso total ao sistema. |
| `is_global_admin` | boolean | `true` se for `is_super_admin` OU admin em alguma loja. |
| `has_fabrica_access` | boolean | `true` se possui o role `fabrica` (Spatie). |
| `roles` | string[] | Lista de roles globais do Spatie (ex: `["fabrica"]`). |
| `stores[].role` | string | Papel do usuário em cada loja específica. |

---

## Tipos de Usuário

### 1. Super Admin
```js
user.is_super_admin === true
```
- Acesso **total** ao sistema
- Pode ver todas as lojas
- Pode acessar portal da fábrica
- Pode gerenciar usuários globalmente

### 2. Admin de Loja
```js
user.is_global_admin === true && !user.is_super_admin
```
- Admin em pelo menos uma loja
- Acesso ao portal de produção
- Pode acessar portal da fábrica (apenas visualização)
- Ver: `stores.filter(s => s.role === 'admin')`

### 3. Fábrica
```js
user.has_fabrica_access === true
// ou
user.roles.includes('fabrica')
```
- Usuário exclusivo da fábrica
- Acesso ao portal `/fabrica/*`
- Pode aceitar e despachar pedidos

### 4. Gerente
```js
stores.some(s => s.role === 'gerente')
```
- Gerencia vendedores e metas da loja
- Pode aprovar fechamentos de caixa

### 5. Conferente
```js
stores.some(s => s.role === 'conferente')
```
- Confere fechamentos de caixa

### 6. Vendedor
```js
stores.some(s => s.role === 'vendedor')
```
- Registra vendas e turnos

---

## Decisão de Telas

### Exemplo: Mostrar menu "Portal Fábrica"

```typescript
const showFabricaMenu = me.user.has_fabrica_access || me.user.is_global_admin;
```

### Exemplo: Mostrar menu "Produção" (carrinho de produção)

```typescript
const showProducaoMenu = me.user.is_global_admin || me.stores.some(s => s.role === 'admin');
```

### Exemplo: Mostrar menu "Capas Personalizadas"

```typescript
const showCapasMenu = me.user.is_super_admin || me.stores.length > 0;
```

---

## Matriz de Acesso por Endpoint

| Endpoint | Super Admin | Admin Loja | Fábrica | Gerente | Conferente | Vendedor |
|----------|-------------|------------|---------|---------|------------|----------|
| `/producao/*` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `/fabrica/*` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `/capas-personalizadas/*` | ✅ | ✅ | ❌ | ✅ | ✅ | ✅ |
| `/admin/*` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |

---

## Usuário de Teste - Fábrica

Para testar o portal da fábrica:

| Campo | Valor |
|-------|-------|
| Email | `fabrica@maiscapinhas.com.br` |
| Senha | `password` |
| Role | `fabrica` |

**Response do `/me` para este usuário:**
```json
{
  "user": {
    "is_super_admin": false,
    "is_global_admin": false,
    "has_fabrica_access": true,
    "roles": ["fabrica"]
  },
  "stores": []
}
```

---

Qualquer dúvida, é só chamar! 🚀
