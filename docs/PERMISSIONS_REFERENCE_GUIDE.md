# 📋 Mapeamento Completo: Endpoints × Permissões × Telas

> **Guia de referência** para frontend e administradores entenderem o sistema de permissões.

---

## 🎯 Conceitos

| Tipo | Prefixo | O que controla | Exemplo |
|------|---------|----------------|---------|
| **Ability** | `[módulo].[ação]` | Ações em endpoints | `pedidos.create` |
| **Screen** | `screen.[área]` | Visibilidade de telas/menus | `screen.pedidos.list` |
| **Feature** | `feature.[nome]` | Funcionalidades especiais | `feature.whatsapp-notifications` |

---

## 📊 Cobertura por Módulo

### ✅ Módulos Totalmente Cobertos

| Módulo | Abilities | Screens | Endpoints |
|--------|-----------|---------|-----------|
| Pedidos | 7 | 4 | ✅ 7/7 |
| Capas | 9 | 4 | ✅ 12/12 |
| Caixa | 5 | 4 | ✅ 9/9 |
| Clientes | 4 | 1 | ✅ 8/8 |
| Produção | 4 | 3 | ✅ 10/10 |
| Fábrica | 3 | 2 | ✅ 5/5 |
| Admin | 5 | 6 | ✅ 5/5 |

### ⚠️ Módulos Precisando Atenção

| Módulo | Status | Gap |
|--------|--------|-----|
| **Sales (Vendas)** | ❌ SEM PERMISSÕES | Precisa `sales.*` |
| **Finance (Extrato)** | ⚠️ Parcial | Falta `finance.*` separado |
| **Goals (Metas)** | ⚠️ Parcial | Falta `goals.*` |
| **Rules (Regras)** | ⚠️ Parcial | Falta `rules.*` |
| **Phone Catalog** | ⚠️ Em admin | OK, usa `admin.catalog.manage` |

---

## 📝 Permissões Faltantes (Recomendações)

### 1. Sales (Vendas)
```
sales.view         → Ver vendas (próprias)
sales.view-all     → Ver todas as vendas
sales.create       → Registrar venda
sales.update       → Editar venda
sales.delete       → Excluir venda
screen.sales       → Menu Vendas
screen.sales.list  → Lista de Vendas
```

### 2. Goals (Metas)
```
goals.view         → Ver metas
goals.create       → Criar meta
goals.update       → Editar meta
goals.delete       → Excluir meta
goals.splits       → Definir splits
```

### 3. Rules (Regras)
```
rules.bonus.view     → Ver regras de bônus
rules.bonus.manage   → Gerenciar bônus
rules.commission.view    → Ver regras de comissão
rules.commission.manage  → Gerenciar comissões
```

---

## 🗂️ Mapeamento Endpoint → Permissão

### Pedidos
| Endpoint | Método | Permissão |
|----------|--------|-----------|
| `/pedidos` | GET | `pedidos.view` |
| `/pedidos` | POST | `pedidos.create` |
| `/pedidos/{id}` | GET | `pedidos.view` |
| `/pedidos/{id}` | PATCH | `pedidos.update` |
| `/pedidos/{id}` | DELETE | `pedidos.delete` |
| `/pedidos/{id}/status` | PATCH | `pedidos.status.update` |
| `/pedidos/bulk-status` | POST | `pedidos.bulk-status` |

### Capas Personalizadas
| Endpoint | Método | Permissão |
|----------|--------|-----------|
| `/capas-personalizadas` | GET | `capas.view` |
| `/capas-personalizadas` | POST | `capas.create` |
| `/capas-personalizadas/{id}` | GET | `capas.view` |
| `/capas-personalizadas/{id}` | PATCH | `capas.update` |
| `/capas-personalizadas/{id}` | DELETE | `capas.delete` |
| `/capas-personalizadas/{id}/status` | PATCH | `capas.status.update` |
| `/capas-personalizadas/{id}/payment` | PATCH | `capas.payment.update` |
| `/capas-personalizadas/bulk-status` | POST | `capas.bulk-status` |
| `/capas-personalizadas/send-to-production` | POST | `capas.send-production` |

### Caixa
| Endpoint | Método | Permissão |
|----------|--------|-----------|
| `/cash/shifts` | GET | `caixa.view` |
| `/cash/shifts` | POST | `caixa.shift.open` |
| `/cash/closings/{id}` | POST | `caixa.closing.create` |
| `/cash/closings/{id}/approve` | POST | `caixa.closing.approve` |
| `/cash/closings/{id}/reject` | POST | `caixa.closing.reject` |

### Clientes
| Endpoint | Método | Permissão |
|----------|--------|-----------|
| `/customers` | GET | `customers.view` |
| `/customers` | POST | `customers.create` |
| `/customers/{id}` | PUT/PATCH | `customers.update` |
| `/customers/{id}` | DELETE | `customers.delete` |

---

## 🖥️ Mapeamento Tela → Screen

| Tela Frontend | Screen | Quem vê |
|---------------|--------|---------|
| Dashboard | `screen.dashboard` | Todos |
| Comunicados | `screen.comunicados` | Todos |
| Clientes | `screen.clientes` | Vendedor+ |
| Lista Pedidos | `screen.pedidos.list` | Vendedor+ |
| Novo Pedido | `screen.pedidos.create` | Vendedor+ |
| Lista Capas | `screen.capas.list` | Vendedor+ |
| Nova Capa | `screen.capas.create` | Vendedor+ |
| Enviar Produção | `screen.capas.production` | Gerente+ |
| Meu Turno | `screen.caixa.shift` | Vendedor+ |
| Fechamento | `screen.caixa.closing` | Vendedor+ |
| Aprovar Fechamento | `screen.caixa.approve` | Conferente+ |
| Ranking | `screen.gestao.ranking` | Gerente+ |
| Performance Lojas | `screen.gestao.lojas` | Gerente+ |
| Config Usuários | `screen.config.usuarios` | Gerente+ |
| Logs Auditoria | `screen.admin.logs` | Admin+ |
| WhatsApp Instances | `screen.admin.whatsapp` | Super Admin |
| Roles | `screen.admin.roles` | Super Admin |

---

## 🔑 Features Especiais

| Feature | Descrição | Quem tem |
|---------|-----------|----------|
| `feature.whatsapp-notifications` | Enviar WhatsApp ao cliente | Admin, Gerente |
| `feature.bulk-operations` | Operações em lote | Admin |
| `feature.export-excel` | Exportar relatórios | Admin, Gerente |

---

## 📈 Hierarquia de Roles

```
Super Admin (100) ──→ Todas as permissões
Admin (90)         ──→ Tudo menos roles/permissions
Fábrica (80)       ──→ Portal fábrica apenas
Gerente (70)       ──→ Loja + relatórios + aprovar
Conferente (60)    ──→ Aprovar fechamentos
Estoquista (50)    ──→ Visualização apenas
Vendedor (40)      ──→ Vendas + caixa próprio
```

---

## 🚀 Próximas Melhorias Sugeridas

1. **Adicionar permissões para Sales** - Módulo de vendas sem controle
2. **Refinar Finance/Goals/Rules** - Separar abilities mais granulares
3. **Descrições detalhadas** - Adicionar `description` em cada permission
4. **Validação de endpoints** - Aplicar middleware em todas as rotas

---

*Documento gerado em 2026-01-16*
