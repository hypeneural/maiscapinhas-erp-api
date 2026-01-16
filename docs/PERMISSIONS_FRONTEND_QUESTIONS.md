# 📋 Sistema de Permissões - Perguntas para o Frontend

> **De:** Backend  
> **Para:** Time Frontend  
> **Data:** 16/01/2026

---

## 🎯 O Que Foi Implementado

### Estrutura de Permissões

Criamos um sistema com **3 tipos de permissões**:

| Tipo | Prefixo | Propósito | Exemplo |
|------|---------|-----------|---------|
| **Ability** | `modulo.acao` | Ações em endpoints | `pedidos.create`, `capas.delete` |
| **Screen** | `screen.area` | Visibilidade de telas | `screen.dashboard`, `screen.pedidos.list` |
| **Feature** | `feature.nome` | Funcionalidades especiais | `feature.whatsapp-notifications` |

### Hierarquia de Resolução

```
Super Admin (bypassa tudo)
    ↓
User Override (permissão específica do usuário)
    ↓
Store Override (permissão da loja)
    ↓
Role Permission (permissão do cargo)
```

### O que o `/me` retorna hoje:

```json
{
  "user": { ... },
  "stores": [{ "id": 1, "name": "Loja A", "role": "gerente" }],
  "permissions": {
    "global": { "granted": ["pedidos.view"], "denied": [] },
    "by_store": { "1": { "granted": ["capas.create"] } }
  },
  "screens": {
    "global": ["screen.dashboard", "screen.pedidos.list"],
    "by_store": { "1": ["screen.capas.production"] }
  },
  "features": ["feature.whatsapp-notifications"],
  "menu": [ /* menu filtrado */ ]
}
```

---

## ❓ Perguntas para Vocês

### 1. Dashboard Diferenciada por Role

O exemplo que vocês deram é excelente. Hoje temos **3 tipos de dashboard**:
- `/dashboard/vendedor` 
- `/dashboard/conferente`
- `/dashboard/admin`

**Pergunta:** Preferem que o backend:
- **A)** Retorne qual dashboard o usuário deve ver (ex: `"default_dashboard": "vendedor"`)
- **B)** Retorne permissões individuais para cada KPI (ex: `screen.dashboard.kpi.vendas`, `screen.dashboard.kpi.meta`)
- **C)** Uma única rota `/dashboard` que retorna dados diferentes baseado no role

---

### 2. Telas com Variações por Nível

Vocês mencionaram que uma tela pode ter **componentes diferentes** por nível de usuário.

**Exemplo:** Na tela de Pedidos, o vendedor vê só os seus pedidos, mas o gerente vê todos.

**Pergunta:** Preferem:
- **A)** Abilities separadas (`pedidos.view` vs `pedidos.view-all`) e vocês controlam no front
- **B)** O backend retornar flags específicas (ex: `"can_view_all_orders": true`)
- **C)** Screens com sufixo (ex: `screen.pedidos.list.all` vs `screen.pedidos.list.own`)

---

### 3. Granularidade de Botões/Ações

Para ações dentro de uma tela (ex: botão "Cancelar Pedido"):

**Pergunta:** Vocês usariam:
- **A)** Abilities específicas (`pedidos.cancel`, `capas.approve`, etc.)
- **B)** Uma ability genérica (`pedidos.update`) e regra de negócio no front
- **C)** Features (ex: `feature.cancel-orders`)

---

### 4. Menu Pré-Filtrado

O backend já retorna o menu **filtrado** baseado nas screens do usuário.

**Pergunta:** Isso está bom ou preferem:
- **A)** Receber o menu completo e filtrar no front
- **B)** Receber apenas as screens e montar o menu no front
- **C)** Manter como está (menu já filtrado)

---

### 5. Permissões Temporárias

Implementamos suporte a **permissões com expiração** (ex: acesso temporário a um relatório).

**Pergunta:** Vocês precisam de UI para:
- **A)** Mostrar um badge "Acesso expira em X dias"
- **B)** Filtrar permissões temporárias na listagem do admin
- **C)** Notificar o usuário quando uma permissão está prestes a expirar

---

### 6. Contexto de Loja

Hoje o usuário pode ter permissões **globais** ou **por loja**.

**Pergunta:** Como vocês gostariam de lidar com isso no front?
- **A)** Usuário seleciona a loja ativa e vocês filtram permissões
- **B)** Backend considera a loja do request (header `X-Store-Id`)
- **C)** Vocês verificam `permissions.by_store[storeId]` dinamicamente

---

### 7. Faltam Permissões?

Revisem a lista atual e digam se precisam de algo mais:

**Screens existentes:**
- `screen.dashboard`
- `screen.pedidos`, `screen.pedidos.list`, `screen.pedidos.create`, `screen.pedidos.bulk`
- `screen.capas`, `screen.capas.list`, `screen.capas.create`, `screen.capas.production`
- `screen.caixa`, `screen.caixa.shift`, `screen.caixa.closing`, `screen.caixa.approve`
- `screen.faturamento`, `screen.faturamento.extrato`, etc.
- `screen.gestao`, `screen.gestao.ranking`, etc.
- `screen.admin`, `screen.admin.roles`, `screen.admin.permissions`
- ...

**Features existentes:**
- `feature.whatsapp-notifications`
- `feature.bulk-operations`
- `feature.export-excel`

**O que falta?**

---

## 🔧 APIs Disponíveis para Admin

| Endpoint | Descrição |
|----------|-----------|
| `GET /admin/permissions` | Listar todas (filtros: type, module, group_by) |
| `GET /admin/permissions/grouped` | Agrupadas por módulo |
| `GET /admin/permissions/by-type` | Separadas por tipo |
| `GET /admin/permissions/conventions` | Guia de nomenclatura |
| `POST /admin/users/{id}/permission-overrides` | Dar/negar permissão a usuário |
| `POST /admin/stores/{id}/permission-overrides` | Dar/negar permissão a loja |
| `POST /admin/users/{id}/roles` | Atribuir role a usuário |
| `PUT /admin/users/{id}/roles/sync` | Sincronizar todos os roles |

---

## 📝 Aguardamos Feedback!

Por favor respondam as perguntas numeradas para alinharmos a implementação.

Qualquer dúvida, estamos à disposição!
