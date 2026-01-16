# 📦 Briefing Frontend: Sistema Modular v2

> **Data:** 16/01/2026  
> **Para:** Time de Frontend  
> **De:** Time de Backend

---

## 1. Visão Geral: O Que Mudou

### Antes (Monolítico)
- Cada tela tinha regras hardcoded
- Permissions eram verificadas uma a uma
- Tooltips, labels, textos duplicados no front
- Difícil adicionar novos módulos

### Agora (Modular)
- **Módulos** são pacotes auto-contidos
- **TUDO** vem da API: textos, ações, status, permissions
- Super Admin pode editar quem faz qual transição de status
- Adicionar novo módulo = criar 1 arquivo PHP

---

## 2. O Que é um Módulo

Um **módulo** contém:

| Componente | Descrição | Exemplo |
|------------|-----------|---------|
| **Statuses** | Lista de status possíveis | Solicitado, Disponível, Concluído |
| **Transitions** | Quem pode mudar de X para Y | Vendedor pode: Disponível → Aguardando |
| **Actions** | Botões com tooltips, shortcuts | "Avisar Cliente" (shortcut: A) |
| **Permissions** | Abilities agrupadas | Grupo "Visualização": view, view-all |
| **Texts** | Labels, tooltips, empty states | "Nenhum pedido encontrado" |
| **Documentation** | FAQ, workflow steps | "Como cancelar um pedido?" |

---

## 3. Módulos Existentes

| ID | Nome | Status | Permissions |
|----|------|--------|-------------|
| `pedidos-simples` | Pedidos Simples | 6 | 11 |
| `capas-personalizadas` | Capas Personalizadas | 10 | 16 |

---

## 4. API Principal

### GET `/api/v1/admin/modules/{id}/full`

**Retorna TUDO que o frontend precisa para renderizar:**

```json
{
  "id": "pedidos-simples",
  "name": "Pedidos Simples",
  "icon": "FileCheck",
  
  "texts": {
    "menu_label": "Pedidos",
    "menu_tooltip": "Gerenciar pedidos de encomenda",
    "page_title": "Pedidos de Encomenda",
    "page_description": "Acompanhe todos os pedidos...",
    "create_button": "Novo Pedido",
    "empty_state": "Nenhum pedido encontrado."
  },
  
  "statuses": {
    "1": {
      "name": "solicitado",
      "label": "Solicitado",
      "description": "Pedido criado, aguardando processamento.",
      "color": "blue",
      "icon": "clipboard-list",
      "badge_variant": "secondary",
      "can_edit": true,
      "final": false
    }
  },
  
  "actions": {
    "avisar_cliente": {
      "label": "Avisar Cliente",
      "icon": "Bell",
      "tooltip": "Enviar notificação WhatsApp...",
      "shortcut": "A",
      "permission": "pedidos.status.to-aguardando",
      "available_in_status": [3],
      "confirm": false
    },
    "cancelar": {
      "label": "Cancelar Pedido",
      "icon": "X",
      "tooltip": "Cancelar este pedido.",
      "confirm": true,
      "confirm_title": "Cancelar Pedido",
      "confirm_message": "Tem certeza?",
      "requires_fields": ["cancelation_reason"]
    }
  },
  
  "permission_groups": {
    "visualizacao": {
      "label": "Visualização",
      "icon": "Eye",
      "description": "Controla o que o usuário pode ver",
      "permissions": ["pedidos.view", "pedidos.view-all", "pedidos.view-global"]
    },
    "gerenciamento": {
      "label": "Gerenciamento",
      "icon": "Edit",
      "permissions": ["pedidos.create", "pedidos.update", "pedidos.cancel"]
    }
  },
  
  "transitions": {
    "1": [3, 6],
    "3": [4, 6]
  },
  
  "transition_role_matrix": {
    "1": {
      "3": ["admin", "gerente"],
      "6": ["vendedor", "admin", "gerente"]
    }
  },
  
  "conditional_fields": {
    "cancelado": {
      "cancelation_reason": {
        "type": "select",
        "required": true,
        "options": { "customer_request": "Por solicitação do cliente" }
      }
    }
  },
  
  "documentation": {
    "overview": "O módulo gerencia encomendas...",
    "workflow": {
      "title": "Fluxo do Pedido",
      "steps": ["1. Vendedor cria", "2. Admin marca disponível"]
    },
    "faq": {
      "Como cancelar?": "Clique em Cancelar..."
    }
  }
}
```

---

## 5. O Que Precisa Mudar no Frontend

### 5.1 📋 Renderização Dinâmica de Botões

**Antes:**
```tsx
{status === 3 && <Button>Avisar Cliente</Button>}
```

**Depois:**
```tsx
{moduleActions.map(action => 
  action.available_in_status.includes(status) && 
    <Button tooltip={action.tooltip}>{action.label}</Button>
)}
```

### 5.2 🎨 Badges de Status

**Usar `badge_variant` e `description` da API:**
```tsx
<Badge variant={status.badge_variant}>
  {status.label}
</Badge>
<Tooltip>{status.description}</Tooltip>
```

### 5.3 🔐 UI de Permissões (Super Admin)

**Criar tela para editar matriz de transições:**

```
┌─────────────────────────────────────────────────────────┐
│ Transição: Solicitado → Disponível na Loja              │
│                                                         │
│ Quem pode executar:                                     │
│ [x] Admin  [x] Gerente  [ ] Vendedor  [x] Super Admin   │
└─────────────────────────────────────────────────────────┘
```

### 5.4 📝 Textos da API

**Não hardcodar:**
- Título da página → `texts.page_title`
- Botão criar → `texts.create_button`
- Empty state → `texts.empty_state`
- Tooltips → `actions.*.tooltip`

### 5.5 ⌨️ Shortcuts

**Registrar atalhos de teclado:**
```tsx
useHotkey(action.shortcut, () => executeAction(action.id))
```

---

## 6. Perguntas para o Frontend

### Performance

1. **Cache do módulo**: Vocês preferem cachear o `/full` no React Query ou no localStorage?
2. **Carregamento**: Carregar módulo uma vez no login ou lazy load por página?

### UX

3. **Status badges**: Usar `badge_variant` do backend ou ter tema próprio?
4. **Confirmação**: Usar nosso `confirm_message` ou um modal customizado?
5. **Shortcuts**: Querem que a gente envie as teclas como `Ctrl+A` ou só `A`?

### Super Admin

6. **Edição de transições**: Preferem uma matriz tipo spreadsheet ou checkboxes?
7. **Visualização de workflow**: Querem diagram interativo (Mermaid) ou lista?

### Dados

8. **Campos condicionais**: Enviar estrutura de form ou só os campos?
9. **Validações**: Enviar regras de validação (min, max, regex) na API?

---

## 7. Endpoints Disponíveis

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/admin/modules` | Lista todos os módulos |
| GET | `/admin/modules/{id}` | Detalhes básicos |
| GET | `/admin/modules/{id}/full` | **TUDO** para renderização |
| GET | `/admin/modules/{id}/transitions` | Só matriz de transições |
| PUT | `/admin/modules/{id}/transitions` | Editar matriz |

---

## 8. Próximos Passos (Backend)

- [ ] Comando `php artisan module:make Nome` para facilitar criação
- [ ] Sincronizar permissions automaticamente
- [ ] Jobs de automação (cancelar após 20 dias)
- [ ] Webhook para notificar front quando módulo muda

---

## 9. Contato

Dúvidas? Falar com @backend no Slack ou abrir issue no GitHub.

**Aguardamos feedback! 🚀**
