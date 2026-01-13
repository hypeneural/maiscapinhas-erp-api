# Resposta às Perguntas e Sugestões - API de Produção / Capas Personalizadas

> **Data**: 2026-01-13
> **Equipe**: Backend → Frontend
> **Status**: ✅ Implementado

---

## 🔴 Bugs Críticos - RESOLVIDOS

### Bug 1: Capa em Carrinho Cancelado Bloqueia Nova Adição ✅

**Status**: Corrigido

**O que foi feito**:
- Adicionamos detecção automática de capas "órfãs" (vinculadas a carrinhos cancelados)
- Ao tentar adicionar uma capa ao carrinho, ela é automaticamente liberada se estava em um carrinho cancelado
- Não haverá mais erro 500 - a capa simplesmente será adicionada ao novo carrinho

**Abordagem escolhida**: Combinação das opções A e C sugeridas:
- O `cancelCart()` já deleta os itens fisicamente (opção A)
- Adicionamos verificação de status do pedido antes de bloquear (opção C)

---

### Bug 2: Admin/Super Admin Não Consegue Ver Pedidos da Fábrica ✅

**Status**: Corrigido

**O que foi feito**:
- Middleware `EnsureIsFabrica` atualizado para aceitar:
  - Role `fabrica` ✅
  - Admin de qualquer loja ✅
  - Super Admin ✅

**Código implementado**:
```php
if (!$user || (!$user->hasRole('fabrica') && !$user->isGlobalAdmin())) {
    // bloqueia
}
```

---

## 🟡 Melhorias Sugeridas - IMPLEMENTADAS

### 1. Validação Aprimorada Antes de Adicionar ao Carrinho ✅

- Capas em carrinhos cancelados são automaticamente liberadas
- Endpoint `/carrinho/validar` também considera capas órfãs como elegíveis

---

### 2. Endpoint para Limpar Itens de Carrinhos Cancelados ✅

**Novo endpoint**:
```http
POST /api/v1/producao/admin/limpar-itens-cancelados
Authorization: Bearer {token}
```

**Response**:
```json
{
  "message": "5 capa(s) liberada(s) de pedidos cancelados.",
  "data": { "released_count": 5 }
}
```

---

### 3. Status Detalhado no GET Carrinho ✅

**Novos campos no response de `GET /api/v1/producao/carrinho`**:
```json
{
  "id": 3,
  "status": 1,
  "items": [...],
  "can_add_more": true,
  "blockers": []
}
```

---

### 4. Histórico de Capas em Carrinhos ✅

**Novos campos no response de capas personalizadas**:
```json
{
  "id": 26,
  "status": 1,
  "producao_pedido_id": 3,
  "producao_history": [
    { "pedido_id": 2, "status": "CANCELADO", "status_label": "Cancelado", "added_at": "2026-01-12" },
    { "pedido_id": 3, "status": "CARRINHO_ABERTO", "status_label": "Carrinho Aberto", "added_at": "2026-01-13" }
  ]
}
```

---

## 📋 Checklist de Endpoints - ATUALIZADO

| Endpoint | Status |
|----------|--------|
| `POST /carrinho/itens` | ✅ Erro 500 corrigido - libera capas órfãs automaticamente |
| `GET /fabrica/pedidos` | ✅ Admin/Super admin agora tem acesso |
| `DELETE /carrinho` | ✅ Libera capas corretamente (já funcionava) |
| `GET /producao/pedidos` | ✅ Funciona |
| `POST /carrinho/validar` | ✅ Funciona + considera órfãs |
| `POST /admin/limpar-itens-cancelados` | ✅ **NOVO** - Limpeza em massa |

---

## 🔐 Resumo de Permissões - ATUALIZADO

| Endpoint | fabrica | admin | super_admin |
|----------|---------|-------|-------------|
| `/producao/*` | ❌ | ✅ | ✅ |
| `/fabrica/*` | ✅ | ✅ | ✅ |

---

## Respostas às Perguntas Gerais

| # | Pergunta | Resposta |
|---|----------|----------|
| 1 | Quando um carrinho é cancelado, os itens são removidos? | **Sim, fisicamente** via `$carrinho->itens()->delete()` e status das capas é revertido |
| 2 | A constraint é global ou por pedido? | **Global** (`UNIQUE(capa_personalizada_id)`). O design atual evita duplicatas deletando os itens ao cancelar. |
| 3 | Existe job/cron para limpar carrinhos abandonados? | **Não existe ainda**. Podemos criar se necessário. |
| 4 | O admin pode visualizar o Portal Fábrica? | **Sim, agora pode!** |
| 5 | Existe log de auditoria? | **Sim**, via tabela `producao_eventos` que registra todas as ações. |

---

Qualquer dúvida, estamos à disposição! 🚀
