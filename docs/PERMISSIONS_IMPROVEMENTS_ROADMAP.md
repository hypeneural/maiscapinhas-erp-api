# 📋 Análise do Sistema de Permissões - Melhorias Futuras

> **Data:** 16/01/2026  
> **Versão Atual:** 180 permissions

---

## 1. Estado Atual do Sistema

### ✅ O que já temos

| Componente | Status | Descrição |
|------------|--------|-----------|
| **Abilities** | ✅ | Ações granulares (view, create, update, delete) |
| **Screens** | ✅ | Controle de acesso a telas |
| **Features** | ✅ | Funcionalidades especiais |
| **Roles** | ✅ | 7 roles com hierarquia |
| **Overrides** | ✅ | Usuario + Loja |
| **Temporário** | ✅ | Permissões com expiração |

### ❌ O que precisa melhorar

| Item | Prioridade | Descrição |
|------|------------|-----------|
| **Regras de Negócio por Status** | 🔴 Alta | Validação de quem pode alterar para qual status |
| **Módulos como WordPress** | 🟡 Média | Agrupar permissions como módulos instaláveis |
| **Workflow Engine** | 🟡 Média | Fluxo de estados com transições permitidas |
| **Campos Condicionais** | 🟡 Média | Campos obrigatórios por status |
| **Automações** | 🟢 Baixa | Cancelamento automático após X dias |

---

## 2. Proposta: Sistema Modular (Estilo WordPress)

### Conceito

Cada **módulo** é um pacote de funcionalidades que inclui:
- Permissions (abilities, screens, features)
- Regras de status/transição
- Campos obrigatórios
- Automações

### Estrutura Proposta

```
modules/
├── pedidos-simples/
│   ├── module.json          # Definição do módulo
│   ├── permissions.php      # Permissions do módulo
│   ├── status-workflow.php  # Estados e transições
│   ├── validations.php      # Regras de negócio
│   └── automations.php      # Jobs automáticos
├── capas-personalizadas/
├── caixa/
├── comunicados/
└── ...
```

### module.json Exemplo

```json
{
  "name": "pedidos-simples",
  "display_name": "Pedidos Simples",
  "version": "1.0.0",
  "description": "Gerenciamento de pedidos de encomenda",
  "dependencies": ["customers", "payment-methods"],
  "permissions": {
    "abilities": [
      "pedidos.view",
      "pedidos.view-all",
      "pedidos.create",
      "pedidos.update",
      "pedidos.cancel",
      "pedidos.status.update",
      "pedidos.assign-seller"
    ],
    "screens": [
      "screen.pedidos",
      "screen.pedidos.list",
      "screen.pedidos.create",
      "screen.pedidos.detail"
    ]
  }
}
```

---

## 3. Módulo: Pedidos Simples - Regras de Negócio

### 3.1 Status do Pedido

| Status | ID | Descrição |
|--------|-----|-----------|
| **Solicitado** | 1 | Pedido criado, aguardando processamento |
| **Disponível na Loja** | 2 | Produto chegou na loja |
| **Aguardando Cliente** | 3 | Cliente foi avisado |
| **Venda Concluída** | 4 | Cliente retirou e pagou |
| **Pedido Cancelado** | 5 | Pedido cancelado |

### 3.2 Fluxo de Status por Nível

```
┌─────────────────────────────────────────────────────────────────────┐
│                    WORKFLOW DE STATUS                               │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  VENDEDOR:                                                          │
│  ┌──────────────┐                                                   │
│  │  Solicitado  │───────────────────────────► Cancelado             │
│  └──────────────┘        (com justificativa)                        │
│         │                                                           │
│         ▼ (automático via admin)                                    │
│  ┌──────────────────────┐                                           │
│  │  Disponível na Loja  │──────────► Cancelado                      │
│  └──────────────────────┘           (com justificativa)             │
│         │                                                           │
│         ▼ [Botão AVISAR CLIENTE]                                    │
│  ┌──────────────────────┐                                           │
│  │  Aguardando Cliente  │──┬──────► Venda Concluída                 │
│  └──────────────────────┘  │        (valor, forma pgto, obs)        │
│                            └──────► Cancelado                       │
│                                     (justificativa obrigatória)     │
│                                                                     │
│  ADMIN / GERENTE / SUPER ADMIN:                                     │
│  Pode alterar para qualquer status EXCETO reverter para Solicitado  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.3 Matriz de Transições

| De ↓ / Para → | Solicitado | Disponível | Aguardando | Concluída | Cancelado |
|---------------|------------|------------|------------|-----------|-----------|
| **Solicitado** | - | Admin+ | ❌ | ❌ | ✅ Todos |
| **Disponível** | ❌ | - | ✅ Todos | Admin+ | ✅ Todos |
| **Aguardando** | ❌ | Admin+ | - | ✅ Todos | ✅ Todos |
| **Concluída** | ❌ | ❌ | ❌ | - | ❌ |
| **Cancelado** | ❌ | ❌ | ❌ | ❌ | - |

### 3.4 Permissions Específicas Necessárias

```php
// Novas abilities a adicionar
'pedidos.status.to-disponivel'     // Vendedor NÃO tem
'pedidos.status.to-aguardando'     // Vendedor TEM (via botão)
'pedidos.status.to-concluida'      // Vendedor TEM
'pedidos.status.to-cancelado'      // Vendedor TEM (com justificativa)
'pedidos.assign-seller'            // Vendedor NÃO tem (Admin+)
'pedidos.assign-store'             // Vendedor NÃO tem (Admin+)
```

### 3.5 Campos Obrigatórios por Status

| Status | Campos Obrigatórios |
|--------|---------------------|
| **Cancelado** | `cancelation_reason` (select: Por solicitação do vendedor, Por solicitação do cliente, Indisponibilidade do produto, Outro motivo) + `cancelation_notes` (se Outro) |
| **Venda Concluída** | `payment_date`, `payment_amount`, `payment_method_id`, `notes` (opcional) |

### 3.6 Regras de Criação

| Nível | Pode Criar | Campos Obrigatórios |
|-------|------------|---------------------|
| **Vendedor** | ✅ | Assume seller_id próprio, store_id da loja atual |
| **Admin/Gerente** | ✅ | Pode escolher `seller_id` e `store_id` |
| **Super Admin** | ✅ | Pode escolher qualquer loja/vendedor |

> ⚠️ Se admin/gerente não informar seller, assume responsabilidade

### 3.7 Regras de Visualização

| Nível | Pode Ver |
|-------|----------|
| **Vendedor** | Apenas seus pedidos + pedidos da loja |
| **Gerente** | Todos da loja |
| **Admin** | Todos das lojas que gerencia |
| **Super Admin** | Todos |

### 3.8 Automações

| Automação | Condição | Ação |
|-----------|----------|------|
| **Cancelamento por Inércia** | Status "Disponível na Loja" há > 20 dias | Cancelar com justificativa "Inércia do vendedor em avisar o cliente" |
| **Cancelamento por Não Comparecimento** | Status "Aguardando Cliente" há > 20 dias | Cancelar com justificativa "Não comparecimento do cliente" |
| **Notificação WhatsApp** | Ao mudar para "Aguardando Cliente" | Enviar mensagem para cliente |
| **Lembrete 5+5 dias** | 5 dias após notificação | Reenviar lembrete |

### 3.9 UI: Listagem do Vendedor

```
┌─────────────────────────────────────────────────────────────────────┐
│  MEUS PEDIDOS                                   [+ Novo Pedido]     │
├─────────────────────────────────────────────────────────────────────┤
│  Filtros: [Solicitado] [Disponível] [Aguardando] [Todos]            │
├─────────────────────────────────────────────────────────────────────┤
│  # │ Cliente        │ Produto      │ Status           │ Ações      │
│────┼────────────────┼──────────────┼──────────────────┼────────────│
│  1 │ João Silva     │ iPhone 15    │ 📦 Disponível    │ [AVISAR]   │
│  2 │ Maria Santos   │ Galaxy S24   │ ⏳ Aguardando    │ [Finalizar]│
│  3 │ Pedro Lima     │ Redmi Note   │ 📝 Solicitado    │ [Cancelar] │
└─────────────────────────────────────────────────────────────────────┘

* Pedidos Cancelados e Concluídos: apenas via filtro "Todos"
* Botão AVISAR: muda status direto (sem modal)
```

---

## 4. Módulo: Comunicados - Melhorias

### 4.1 Regras de Exibição

| Tipo | Comportamento |
|------|---------------|
| **Crítico/Advertência** | Modal bloqueante: "Você tem uma mensagem importante. Deseja ver agora?" - Só botão SIM. Persiste após refresh. |
| **Normal** | Banner no topo, pode ser fechado. Remove "Ler Mais". |

### 4.2 Campos do Cadastro

```diff
- Resumo (remover)
+ Tipo: [Normal] [Crítico/Advertência]
+ Data/Hora: Se vazio = envio único imediato
```

---

## 5. Próximas Implementações (Prioridade)

### 🔴 Alta Prioridade

1. [ ] Criar `PedidoStatusPolicy` - validação de transições
2. [ ] Adicionar permissions de status específicos
3. [ ] Campos obrigatórios por status (cancelation_reason, payment_*)
4. [ ] Middleware de transição de status

### 🟡 Média Prioridade

5. [ ] Job de cancelamento automático (20 dias)
6. [ ] Integração WhatsApp para notificação
7. [ ] Lembrete 5+5 dias
8. [ ] Modal bloqueante para comunicados críticos

### 🟢 Baixa Prioridade

9. [ ] Sistema modular como WordPress
10. [ ] Histórico de transições de status

---

## 6. Schema: Novas Colunas Sugeridas

### Tabela `pedidos`

```sql
ALTER TABLE pedidos ADD COLUMN seller_id BIGINT UNSIGNED NULL;
ALTER TABLE pedidos ADD COLUMN cancelation_reason ENUM(
    'seller_request',
    'customer_request', 
    'unavailable_product',
    'seller_inertia',
    'customer_no_show',
    'other'
) NULL;
ALTER TABLE pedidos ADD COLUMN cancelation_notes TEXT NULL;
ALTER TABLE pedidos ADD COLUMN payment_amount DECIMAL(10,2) NULL;
ALTER TABLE pedidos ADD COLUMN payment_date DATE NULL;
ALTER TABLE pedidos ADD COLUMN payment_method_id BIGINT UNSIGNED NULL;
ALTER TABLE pedidos ADD COLUMN customer_notified_at TIMESTAMP NULL;
ALTER TABLE pedidos ADD COLUMN status_changed_at TIMESTAMP NULL;
```

### Tabela `announcements`

```sql
ALTER TABLE announcements ADD COLUMN is_blocking BOOLEAN DEFAULT FALSE;
ALTER TABLE announcements DROP COLUMN summary;
```

---

## 7. Resumo Executivo

| Área | O que fazer |
|------|-------------|
| **Permissions** | Adicionar abilities por transição de status |
| **Validation** | Policy de transição baseada em role + status |
| **UI** | Botão AVISAR direto, sem modal |
| **Automação** | Jobs para cancelamento após 20 dias |
| **Comunicados** | Modal bloqueante para críticos |

---

## 8. Módulo: Capas Personalizadas - Regras de Negócio

### 8.1 Tipos de Capa

| Tipo | Descrição | Requer Foto |
|------|-----------|-------------|
| **Capa Personalizada** | Cliente envia sua própria foto | ✅ Sim |
| **Capa do Catálogo** | Arte pronta do catálogo | ❌ Não |

### 8.2 Status da Capa

| Status | ID | Descrição |
|--------|-----|-----------|
| **Encomenda Solicitada** | 1 | Capa criada pelo vendedor |
| **No Carrinho de Produção** | 2 | Adicionada ao carrinho de envio |
| **Carrinho Enviado** | 3 | Carrinho fechado e enviado à fábrica |
| **Em Produção** | 4 | Fábrica aceitou e está produzindo |
| **Despachado** | 5 | Fábrica despachou |
| **Disponível na Loja** | 6 | Produto chegou na loja |
| **Aguardando Cliente** | 7 | Cliente foi notificado |
| **Venda Concluída** | 8 | Cliente retirou e pagou |
| **Pedido Cancelado** | 9 | Cancelado |

### 8.3 Fluxo de Status Completo

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                           WORKFLOW DE CAPAS PERSONALIZADAS                              │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                         │
│  VENDEDOR:                                                                              │
│  ┌───────────────────────┐                                                              │
│  │  Encomenda Solicitada │───────────────────────────────────► Cancelado                │
│  └───────────────────────┘    (com justificativa)              (somente aqui)           │
│         │                                                                               │
│         ▼ (Admin adiciona ao carrinho)                                                  │
│  ┌───────────────────────────┐                                                          │
│  │  No Carrinho de Produção  │ ─────────────────────► Vendedor NÃO pode mais cancelar   │
│  └───────────────────────────┘                                                          │
│         │                                                                               │
│         ▼ (Admin envia carrinho)                                                        │
│  ┌───────────────────────────┐                                                          │
│  │     Carrinho Enviado      │                                                          │
│  └───────────────────────────┘                                                          │
│         │                                                                               │
│         ├──────────► Fábrica aceita ──────────────────┐                                 │
│         │                                             ▼                                 │
│         │                                    ┌──────────────────┐                       │
│         │                                    │   Em Produção    │                       │
│         │                                    └──────────────────┘                       │
│         │                                             │                                 │
│         │                                             ▼ (Fábrica despacha)              │
│         │                                    ┌──────────────────┐                       │
│         │                                    │    Despachado    │                       │
│         │                                    └──────────────────┘                       │
│         │                                             │                                 │
│         └──────────► Fábrica recusa ──────────► Cancelado (por item, com justificativa) │
│                                                                                         │
│         ▼ (Admin confirma recebimento)                                                  │
│  ┌───────────────────────────┐                                                          │
│  │   Disponível na Loja      │                                                          │
│  └───────────────────────────┘                                                          │
│         │                                                                               │
│         ▼ [Botão AVISAR CLIENTE - Vendedor]                                             │
│  ┌───────────────────────────┐                                                          │
│  │   Aguardando Cliente      │──┬──────► Venda Concluída (com dados de pagamento)       │
│  └───────────────────────────┘  └──────► Cancelado (com justificativa)                  │
│                                                                                         │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

### 8.4 Matriz de Permissões por Role

| Ação | Vendedor | Gerente | Admin | Super Admin | Estoquista | Fábrica |
|------|----------|---------|-------|-------------|------------|---------|
| Criar capa | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| Ver próprias | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| Ver todas da loja | ❌ | ✅ | ✅ | ✅ | ✅ | ❌ |
| Ver modo Kanban | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Cancelar (antes carrinho) | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| Cancelar (após carrinho) | ❌ | ✅ | ✅ | ✅ | ❌ | ✅* |
| Adicionar ao carrinho | ❌ | ✅ | ✅ | ✅ | ✅ | ❌ |
| Enviar carrinho | ❌ | ✅ | ✅ | ✅ | ✅ | ❌ |
| Aceitar pedido | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Despachar | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Marcar disponível loja | ❌ | ✅ | ✅ | ✅ | ✅ | ❌ |
| Avisar cliente | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| Finalizar venda | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| Excluir pedido | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ |

> *Fábrica pode recusar itens individualmente com justificativa

### 8.5 Permissions Específicas para Capas

```php
// Novas abilities a adicionar
'capas.status.to-carrinho'         // Admin, Gerente, Estoquista
'capas.status.to-enviado'          // Admin, Gerente, Estoquista
'capas.status.to-producao'         // Fábrica only
'capas.status.to-despachado'       // Fábrica only
'capas.status.to-disponivel'       // Admin, Gerente, Estoquista
'capas.status.to-aguardando'       // Vendedor (via botão AVISAR)
'capas.status.to-concluida'        // Vendedor
'capas.status.to-cancelado'        // Condicional (ver matriz)
'capas.cancel-before-cart'         // Vendedor
'capas.cancel-after-cart'          // Admin+, Fábrica
'capas.fabrica.accept'             // Fábrica
'capas.fabrica.reject-item'        // Fábrica (por item)
'capas.fabrica.dispatch'           // Fábrica
'capas.view-kanban'                // Todos que podem ver
```

### 8.6 Regras de Pagamento

| Campo | Descrição |
|-------|-----------|
| ~~`unit_price`~~ | **Remover** - substituir por `total_amount` |
| `total_amount` | Valor total do pedido |
| `quantity` | Quantidade de itens |
| `payment_status` | `total`, `partial`, `pending` |
| `payment_1_amount` | Valor do sinal (se parcial) |
| `payment_1_date` | Data do sinal |
| `payment_1_method` | Forma do sinal |
| `payment_2_amount` | Valor na retirada |
| `payment_2_date` | Data da retirada |
| `payment_2_method` | Forma da retirada |

> ⚠️ Pelo menos 1 dos 3 campos de pagamento é obrigatório

### 8.7 UI: Modos de Visualização

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  CAPAS PERSONALIZADAS                      [Lista 📋] [Kanban 📊]    [+ Nova Capa]      │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                         │
│  MODO KANBAN:                                                                           │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐                 │
│  │  Solicitada  │  │  Carrinho    │  │  Produção    │  │ Disponível   │                 │
│  ├──────────────┤  ├──────────────┤  ├──────────────┤  ├──────────────┤                 │
│  │ [Card 1]     │  │ [Card 4]     │  │ [Card 6]     │  │ [Card 8]     │                 │
│  │ [Card 2]     │  │ [Card 5]     │  │ [Card 7]     │  │ [AVISAR] ▶   │                 │
│  │ [Card 3]     │  │              │  │              │  │              │                 │
│  └──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘                 │
│                                                                                         │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

### 8.8 Upload de Imagem

| Requisito | Descrição |
|-----------|-----------|
| **Crop Modal** | Proporção celular 1080x1920px |
| **Aplica em** | Portal cliente + Painel vendedor |
| **Salvar 2 arquivos** | `preview_url` (mockup) + `original_url` (arquivo original) |

### 8.9 Fábrica - Ações Especiais

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  PAINEL FÁBRICA - Pedido Agrupado #123                                                  │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                         │
│  [ ] Capa #456 - iPhone 15 - João Silva                                                 │
│  [ ] Capa #457 - Galaxy S24 - Maria Santos                                              │
│  [x] Capa #458 - Redmi Note - Pedro Lima        ← Selecionado para recusar              │
│                                                                                         │
│  [Aceitar Selecionados]  [Recusar Selecionados]                                         │
│                                                                                         │
│  ┌────────────────────────────────────────────────────────────────┐                     │
│  │  Modal: Justificativa de Recusa                                │                     │
│  │                                                                │                     │
│  │  Capa #458:                                                    │                     │
│  │  [Imagem com defeito ▼]                                        │                     │
│  │  Observações: ___________________________________               │                     │
│  │                                                                │                     │
│  │                           [Confirmar Recusa]                   │                     │
│  └────────────────────────────────────────────────────────────────┘                     │
│                                                                                         │
└─────────────────────────────────────────────────────────────────────────────────────────┘

* Ao aceitar: todos os itens selecionados → Status "Em Produção"
* Ao recusar: cada item recusado → Status "Cancelado" (por item)
* Ao despachar: todos os itens → Status "Despachado"
```

---

## 9. Schema: Capas Personalizadas

### Novas Colunas

```sql
-- Alterar estrutura de pagamento
ALTER TABLE capas_personalizadas DROP COLUMN IF EXISTS unit_price;
ALTER TABLE capas_personalizadas ADD COLUMN total_amount DECIMAL(10,2) NOT NULL;
ALTER TABLE capas_personalizadas ADD COLUMN quantity INT DEFAULT 1;

-- Status de pagamento
ALTER TABLE capas_personalizadas ADD COLUMN payment_status ENUM('total', 'partial', 'pending') DEFAULT 'pending';

-- Pagamento 1 (sinal ou total)
ALTER TABLE capas_personalizadas ADD COLUMN payment_1_amount DECIMAL(10,2) NULL;
ALTER TABLE capas_personalizadas ADD COLUMN payment_1_date DATE NULL;
ALTER TABLE capas_personalizadas ADD COLUMN payment_1_method_id BIGINT UNSIGNED NULL;

-- Pagamento 2 (retirada)
ALTER TABLE capas_personalizadas ADD COLUMN payment_2_amount DECIMAL(10,2) NULL;
ALTER TABLE capas_personalizadas ADD COLUMN payment_2_date DATE NULL;
ALTER TABLE capas_personalizadas ADD COLUMN payment_2_method_id BIGINT UNSIGNED NULL;

-- Imagens
ALTER TABLE capas_personalizadas ADD COLUMN preview_url VARCHAR(255) NULL;
ALTER TABLE capas_personalizadas RENAME COLUMN photo_url TO original_url;

-- Tipo de capa
ALTER TABLE capas_personalizadas ADD COLUMN capa_type ENUM('personalizada', 'catalogo') DEFAULT 'personalizada';

-- Justificativa cancelamento
ALTER TABLE capas_personalizadas ADD COLUMN cancelation_reason TEXT NULL;
```

---

## 10. Roles - Definição Completa

| Role | Nível | Descrição |
|------|-------|-----------|
| **Super Admin** | 100 | Acesso total ao sistema |
| **Admin** | 90 | Gerencia todas as lojas |
| **Gerente** | 70 | Gerencia uma ou mais lojas |
| **Conferente** | 50 | Confere fechamentos de caixa |
| **Estoquista** | 40 | Gerencia carrinho de produção |
| **Vendedor** | 30 | Cria e acompanha pedidos |
| **Fábrica** | 20 | Acesso ao portal da fábrica |

---

## 11. Filtros por Módulo

### Pedidos Simples (Admin)
- Por loja
- Por vendedor
- Por status
- Por período

### Capas Personalizadas (Admin)
- Por loja
- Por vendedor
- Por nome do cliente
- Por WhatsApp
- Por período
- Por tipo (Personalizada/Catálogo)
