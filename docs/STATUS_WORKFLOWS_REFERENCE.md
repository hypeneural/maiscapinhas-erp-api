# 📊 Status Workflows - Documentação Oficial

> **Data:** 16/01/2026  
> **Baseado em:** Enums do sistema (`app/Enums/`)

---

## 1. Pedidos Simples (`PedidoStatus`)

### 1.1 Status Disponíveis

| ID | Enum | Label | Cor | Final? |
|----|------|-------|-----|--------|
| 1 | `SOLICITADO` | Solicitado | 🔵 blue | ❌ |
| 2 | `PRODUTO_INDISPONIVEL` | Produto Indisponível | 🔴 red | ❌ |
| 3 | `DISPONIVEL_LOJA` | Disponível na Loja | 🟡 yellow | ❌ |
| 4 | `VENDA_REALIZADA` | Venda Realizada | 🟢 green | ✅ |
| 5 | `CANCELADO` | Cancelado | ⚫ gray | ✅ |

### 1.2 Workflow Atual (Código)

```
┌──────────────────┐
│   SOLICITADO     │ (ID: 1)
│   (Criação)      │
└────────┬─────────┘
         │
         ├────────────────────────────────────────┐
         ▼                                        ▼
┌──────────────────┐                    ┌──────────────────┐
│    PRODUTO       │ (ID: 2)            │  DISPONÍVEL      │ (ID: 3)
│  INDISPONÍVEL    │                    │   NA LOJA        │
└────────┬─────────┘                    └────────┬─────────┘
         │                                       │
         ▼                                       ├───────────────────┐
┌──────────────────┐                             ▼                   ▼
│    CANCELADO     │ (ID: 5)           ┌──────────────────┐ ┌──────────────────┐
│                  │                   │     VENDA        │ │    CANCELADO     │
└──────────────────┘                   │   REALIZADA      │ │                  │
                                       │   (ID: 4)        │ │   (ID: 5)        │
                                       └──────────────────┘ └──────────────────┘
```

### 1.3 Workflow Proposto (Melhorado)

```
┌──────────────────┐
│   SOLICITADO     │ (ID: 1)           ← Vendedor cria
│                  │
└────────┬─────────┘
         │
         ├──────────────────────────────────────── Cancelar (qualquer role)
         │                                         com justificativa
         ▼
┌──────────────────┐
│  DISPONÍVEL      │ (ID: 3)           ← Admin marca quando produto chega
│   NA LOJA        │
└────────┬─────────┘
         │
         ▼ [AVISAR CLIENTE]            ← Botão vendedor (envia WhatsApp)
┌──────────────────┐
│  AGUARDANDO      │ (ID: 6)           ← ⚠️ NOVO STATUS NECESSÁRIO
│   CLIENTE        │
└────────┬─────────┘
         │
         ├──────────────────────────────┬───────────────────────┐
         ▼                              ▼                       ▼
┌──────────────────┐           ┌──────────────────┐    ┌──────────────────┐
│     VENDA        │ (ID: 4)   │    CANCELADO     │    │  AUTO-CANCELADO  │
│   REALIZADA      │           │                  │    │  (20 dias)       │
└──────────────────┘           └──────────────────┘    └──────────────────┘
```

### 1.4 Permissions por Transição

| Transição | Ability Necessária | Roles Permitidos |
|-----------|-------------------|------------------|
| → Solicitado | `pedidos.create` | Vendedor, Gerente, Admin, Super Admin |
| Solicitado → Disponível | `pedidos.status.to-disponivel` | Admin, Gerente, Super Admin |
| Disponível → Aguardando | `pedidos.status.to-aguardando` | Vendedor (via AVISAR) |
| Aguardando → Venda | `pedidos.status.to-concluida` | Vendedor, Gerente, Admin |
| Qualquer → Cancelado | `pedidos.cancel` | Todos (com justificativa) |

### 1.5 ⚠️ Status Ausente no Código

> O status **"Aguardando Cliente"** (ID: 6) **não existe** no enum atual.  
> É necessário adicionar para implementar o workflow correto.

```php
// PedidoStatus.php - ADICIONAR
case AGUARDANDO_CLIENTE = 6;
```

---

## 2. Capas Personalizadas (`CapaPersonalizadaStatus`)

### 2.1 Status Disponíveis

| ID | Enum | Label | Cor | Final? |
|----|------|-------|-----|--------|
| 1 | `ENCOMENDA_SOLICITADA` | Encomenda Solicitada | 🔵 blue | ❌ |
| 2 | `PRODUTO_INDISPONIVEL` | Produto Indisponível | 🔴 red | ❌ |
| 3 | `DISPONIVEL_LOJA` | Disponível na Loja | 🟡 yellow | ❌ |
| 4 | `VENDA_REALIZADA` | Venda Realizada | 🟢 green | ✅ |
| 5 | `CANCELADA` | Cancelada | ⚫ gray | ✅ |
| 6 | `ENVIADO_PRODUCAO` | Encomendado à Fábrica | 🟠 orange | ❌ |
| 7 | `NO_CARRINHO` | No Carrinho de Produção | ⚪ slate | ❌ |

### 2.2 Workflow Atual (Código)

```
┌────────────────────────┐
│  ENCOMENDA SOLICITADA  │ (ID: 1)    ← Vendedor cria
└───────────┬────────────┘
            │
            ├──────────────────────────────────── Cancelar (vendedor)
            │
            ▼ [Adicionar ao Carrinho] - Admin/Estoquista
┌────────────────────────┐
│    NO CARRINHO         │ (ID: 7)    ← NÃO pode mais cancelar (vendedor)
└───────────┬────────────┘
            │
            ▼ [Enviar Carrinho] - Admin/Estoquista
┌────────────────────────┐
│  ENVIADO PRODUÇÃO      │ (ID: 6)    ← Fábrica recebe
└───────────┬────────────┘
            │
            │ (Via ProducaoPedidoStatus)
            ├──────── Fábrica Aceita ───── Em Produção ───── Despachado ─────┐
            │                                                                 │
            └──────── Fábrica Recusa ────────────────────────────────────────┤
                                                                             │
            ▼ [Confirmar Recebimento] - Admin                                │
┌────────────────────────┐  ◄────────────────────────────────────────────────┘
│   DISPONÍVEL NA LOJA   │ (ID: 3)
└───────────┬────────────┘
            │
            ▼ [AVISAR CLIENTE] - Vendedor
┌────────────────────────┐
│   AGUARDANDO CLIENTE   │ (ID: ?)    ← ⚠️ NÃO EXISTE NO ENUM ATUAL
└───────────┬────────────┘
            │
            ├────────────────────────────┐
            ▼                            ▼
┌────────────────────────┐    ┌────────────────────────┐
│    VENDA REALIZADA     │    │       CANCELADA        │
│       (ID: 4)          │    │       (ID: 5)          │
└────────────────────────┘    └────────────────────────┘
```

### 2.3 ⚠️ Status Ausentes no Código

```php
// CapaPersonalizadaStatus.php - ADICIONAR
case AGUARDANDO_CLIENTE = 8;  // Após Disponível na Loja
case EM_PRODUCAO = 9;         // Fábrica aceitou
case DESPACHADO = 10;         // Fábrica despachou
```

### 2.4 Permissions por Transição

| Transição | Ability Necessária | Roles Permitidos |
|-----------|-------------------|------------------|
| → Encomenda Solicitada | `capas.create` | Vendedor, Gerente, Admin |
| Solicitada → Carrinho | `capas.status.to-carrinho` | Admin, Gerente, Estoquista |
| Carrinho → Enviado | `capas.status.to-enviado` | Admin, Gerente, Estoquista |
| Enviado → Em Produção | `capas.status.to-producao` | Fábrica |
| Em Produção → Despachado | `capas.status.to-despachado` | Fábrica |
| Despachado → Disponível | `capas.status.to-disponivel` | Admin, Estoquista |
| Disponível → Aguardando | `capas.status.to-aguardando` | Vendedor (AVISAR) |
| Aguardando → Venda | `capas.status.to-concluida` | Vendedor |
| Solicitada → Cancelada | `capas.cancel-before-cart` | Vendedor |
| Qualquer → Cancelada | `capas.cancel-after-cart` | Admin, Fábrica |

---

## 3. Produção / Fábrica (`ProducaoPedidoStatus`)

### 3.1 Status Disponíveis

| ID | Enum | Label | Cor | Visível Fábrica? |
|----|------|-------|-----|------------------|
| 1 | `CARRINHO_ABERTO` | Carrinho Aberto | ⚪ slate | ❌ |
| 2 | `ENCOMENDA_REALIZADA` | Encomenda Realizada | 🟠 orange | ✅ |
| 3 | `PEDIDO_ACEITO` | Pedido Aceito | 🩵 teal | ✅ |
| 4 | `PEDIDO_DESPACHADO` | Pedido Despachado | 🟣 indigo | ✅ |
| 5 | `RECEBIDO` | Recebido | 🟢 green | ✅ |
| 6 | `CANCELADO` | Cancelado | 🔴 red | ❌ |

### 3.2 Workflow (Já implementado)

```
┌────────────────────────┐
│   CARRINHO ABERTO      │ (ID: 1)    ← Admin adiciona capas
└───────────┬────────────┘
            │
            ▼ [Fechar Carrinho]
┌────────────────────────┐
│  ENCOMENDA REALIZADA   │ (ID: 2)    ← Fábrica recebe notificação
└───────────┬────────────┘
            │
            ├──────── Fábrica Aceita ─────────────┐
            │                                     ▼
            │                           ┌────────────────────────┐
            │                           │    PEDIDO ACEITO       │ (ID: 3)
            │                           └───────────┬────────────┘
            │                                       │
            │                                       ▼ [Despachar]
            │                           ┌────────────────────────┐
            │                           │   PEDIDO DESPACHADO    │ (ID: 4)
            │                           └───────────┬────────────┘
            │                                       │
            │                                       ▼ [Confirmar Recebimento]
            │                           ┌────────────────────────┐
            │                           │      RECEBIDO          │ (ID: 5)
            │                           └────────────────────────┘
            │
            └──────── Fábrica Recusa ───────────────────────────────────────┐
                                                                            ▼
                                                               ┌────────────────────────┐
                                                               │      CANCELADO         │ (ID: 6)
                                                               └────────────────────────┘
```

### 3.3 Transições Permitidas (Já no Código)

```php
// allowedTransitions() no enum
CARRINHO_ABERTO    → [ENCOMENDA_REALIZADA, CANCELADO]
ENCOMENDA_REALIZADA → [PEDIDO_ACEITO, CANCELADO]
PEDIDO_ACEITO      → [PEDIDO_DESPACHADO, CANCELADO]
PEDIDO_DESPACHADO  → [RECEBIDO]
RECEBIDO           → []  // Final
CANCELADO          → []  // Final
```

### 3.4 Permissions por Transição

| Transição | Ability Necessária | Roles Permitidos |
|-----------|-------------------|------------------|
| → Carrinho Aberto | `producao.cart.create` | Admin, Estoquista |
| Carrinho → Encomenda | `producao.cart.close` | Admin, Estoquista |
| Encomenda → Aceito | `producao.fabrica.accept` | Fábrica |
| Aceito → Despachado | `producao.fabrica.dispatch` | Fábrica |
| Despachado → Recebido | `producao.receive` | Admin, Estoquista |
| Qualquer → Cancelado | `producao.cancel` | Admin, Fábrica |

---

## 4. Mapeamento: Capa ↔ Produção

### Sincronização de Status

| Ação na Produção | Efeito na Capa Individual |
|------------------|---------------------------|
| Carrinho fechado | Capa → `ENVIADO_PRODUCAO` (6) |
| Fábrica aceita | Capa → `EM_PRODUCAO` (9)* |
| Fábrica despacha | Capa → `DESPACHADO` (10)* |
| Admin recebe | Capa → `DISPONIVEL_LOJA` (3) |
| Fábrica recusa item | Capa → `CANCELADA` (5) |

> *Status ainda não existem no enum, precisam ser adicionados

---

## 5. Resumo: Status a Adicionar

### PedidoStatus.php

```php
case AGUARDANDO_CLIENTE = 6;  // Após notificar cliente
```

### CapaPersonalizadaStatus.php

```php
case AGUARDANDO_CLIENTE = 8;  // Após Disponível na Loja
case EM_PRODUCAO = 9;         // Fábrica aceitou
case DESPACHADO = 10;         // Fábrica despachou
```

---

## 6. Permissions a Adicionar

### Pedidos Simples

```php
'pedidos.status.to-disponivel'   // Admin+ marca disponível
'pedidos.status.to-aguardando'   // Vendedor avisa cliente
'pedidos.status.to-concluida'    // Vendedor finaliza
'pedidos.cancel'                 // Cancelar com justificativa
```

### Capas Personalizadas

```php
'capas.status.to-carrinho'       // Admin adiciona
'capas.status.to-enviado'        // Admin fecha carrinho
'capas.status.to-producao'       // Fábrica aceita
'capas.status.to-despachado'     // Fábrica despacha
'capas.status.to-disponivel'     // Admin confirma recebimento
'capas.status.to-aguardando'     // Vendedor avisa cliente
'capas.status.to-concluida'      // Vendedor finaliza
'capas.cancel-before-cart'       // Vendedor cancela (antes carrinho)
'capas.cancel-after-cart'        // Admin/Fábrica cancela
'capas.fabrica.accept'           // Fábrica aceita
'capas.fabrica.reject-item'      // Fábrica recusa item
'capas.fabrica.dispatch'         // Fábrica despacha
```

### Produção

```php
'producao.cart.create'           // Criar carrinho
'producao.cart.close'            // Fechar e enviar
'producao.fabrica.accept'        // Fábrica aceita
'producao.fabrica.dispatch'      // Fábrica despacha
'producao.receive'               // Confirmar recebimento
'producao.cancel'                // Cancelar
```
