# Resposta às Perguntas do Time Frontend - API de Produção

> **Data**: 2026-01-13  
> **Status**: ✅ Implementado

---

## 🔴 Problema 1: Erro 403 ao adicionar ao carrinho

### Causa Raiz
O erro ocorria pois o `AddToCartRequest` usava `$user->hasRole('admin')` que verifica roles do Spatie/Permission. Porém, o sistema usa:
- `is_super_admin` boolean na tabela `users`
- `store_users.role` para admin de loja

### Correção Aplicada ✅
Alterado de:
```php
return $user && ($user->hasRole('admin') || $user->hasRole('super_admin'));
```

Para:
```php
return $user && $user->isGlobalAdmin();
```

O método `isGlobalAdmin()` retorna `true` se:
- Usuário é `super_admin` (`is_super_admin = true`), OU
- Usuário é `admin` em pelo menos uma loja (`store_users.role = 'admin'`)

### Arquivos Modificados
- `app/Http/Requests/Producao/AddToCartRequest.php`
- `app/Http/Requests/Producao/CloseCartRequest.php`

---

## 🟡 Problema 2: Endpoint DELETE /producao/carrinho

### Confirmações ✅

| Pergunta | Resposta |
|----------|----------|
| O endpoint existe? | ✅ Sim, `DELETE /api/v1/producao/carrinho` |
| Comportamento ao cancelar? | O carrinho muda para status `CANCELADO` (6) |
| Capas voltam ao status original? | ✅ Sim, voltam para `ENCOMENDA_SOLICITADA` (1) |
| Carrinho é deletado? | ❌ Não, é mantido com status `CANCELADO` para auditoria |
| Bulk delete de itens? | ✅ **Implementado agora**: `DELETE /itens/bulk` |

### Novo Endpoint: Bulk Remove

```http
DELETE /api/v1/producao/carrinho/itens/bulk
Content-Type: application/json

{
  "item_ids": [1, 2, 3]
}
```

**Response:**
```json
{
  "message": "2 item(ns) removido(s)",
  "data": {
    "removed": [1, 2],
    "errors": [{ "id": 3, "message": "Item não encontrado no carrinho." }],
    "removed_count": 2,
    "error_count": 1
  }
}
```

---

## 🟢 Sugestões Implementadas

### 1. ✅ Carrinho bloqueados com motivo
Já estava implementado. A response inclui:
```json
{
  "blocked": [
    { "id": 28, "reason": "NO_PHOTO", "message": "Capa não possui foto" }
  ]
}
```

**Códigos de bloqueio:**
| Código | Descrição |
|--------|-----------|
| `NOT_FOUND` | Capa não encontrada |
| `CANCELLED` | Capa está cancelada |
| `NO_PHOTO` | Capa não possui foto |
| `ALREADY_IN_CART` | Capa já está no carrinho |
| `ALREADY_SENT` | Capa já foi enviada para fábrica |
| `INVALID_STATUS` | Status deve ser "Encomenda Solicitada" |

### 2. ✅ Endpoint de validação prévia (NOVO)

```http
POST /api/v1/producao/carrinho/validar
Content-Type: application/json

{
  "capa_ids": [27, 28, 29]
}
```

**Response:**
```json
{
  "data": {
    "eligible": [27, 29],
    "blocked": [
      { "id": 28, "reason": "NO_PHOTO", "message": "Capa não possui foto" }
    ],
    "eligible_count": 2,
    "blocked_count": 1
  }
}
```

### 3. 🔜 `eligible_capa_ids` no GET carrinho
**Não implementado por performance** - Listar todas as capas elegíveis pode ser custoso.  
**Sugestão**: Use o endpoint `/validar` passando os IDs das capas que deseja verificar.

---

## 📜 Endpoints Confirmados

| Método | Endpoint | Status |
|--------|----------|--------|
| `GET` | `/producao/carrinho` | ✅ |
| `POST` | `/producao/carrinho/validar` | ✅ **NOVO** |
| `POST` | `/producao/carrinho/itens` | ✅ |
| `DELETE` | `/producao/carrinho/itens/{id}` | ✅ |
| `DELETE` | `/producao/carrinho/itens/bulk` | ✅ **NOVO** |
| `POST` | `/producao/carrinho/fechar` | ✅ |
| `DELETE` | `/producao/carrinho` | ✅ |
| `GET` | `/producao/pedidos` | ✅ |
| `GET` | `/producao/pedidos/{id}` | ✅ |
| `PATCH` | `/producao/pedidos/{id}/receber` | ✅ |
| `DELETE` | `/producao/pedidos/{id}` | ✅ |

---

## 🔐 Permissões Necessárias

Para acessar os endpoints de produção, o usuário deve satisfazer:

```
isGlobalAdmin() = true
```

Isso significa:
- `users.is_super_admin = true`, OU
- Existir registro em `store_users` com `role = 'admin'`

**Como verificar via SQL:**
```sql
SELECT u.id, u.name, u.is_super_admin, 
       EXISTS(SELECT 1 FROM store_users WHERE user_id = u.id AND role = 'admin') as is_store_admin
FROM users u
WHERE u.id = ?
```

---

## 📱 Atualizações no Frontend

### Antes de adicionar ao carrinho (opcional)

```typescript
// Validar capas primeiro
const validation = await api.post('/producao/carrinho/validar', { 
  capa_ids: selectedIds 
});

if (validation.data.blocked_count > 0) {
  // Mostrar aviso para cada capa bloqueada
  validation.data.blocked.forEach(item => {
    toast.warning(`Capa #${item.id}: ${item.message}`);
  });
}

// Adicionar apenas as elegíveis
if (validation.data.eligible_count > 0) {
  await api.post('/producao/carrinho/itens', { 
    capa_ids: validation.data.eligible 
  });
}
```

### Remover múltiplos itens

```typescript
const result = await api.delete('/producao/carrinho/itens/bulk', {
  data: { item_ids: [1, 2, 3] }
});
```

---

## ✅ Checklist de Testes

Testar os seguintes cenários:

- [ ] Usuário super_admin consegue adicionar ao carrinho
- [ ] Usuário admin de loja consegue adicionar ao carrinho
- [ ] Usuário vendedor recebe 403
- [ ] POST /validar retorna eligible e blocked corretamente
- [ ] DELETE /itens/bulk remove múltiplos itens
- [ ] DELETE /carrinho cancela e reverte capas
- [ ] Capa sem foto retorna `NO_PHOTO`
- [ ] Capa já no carrinho retorna `ALREADY_IN_CART`
