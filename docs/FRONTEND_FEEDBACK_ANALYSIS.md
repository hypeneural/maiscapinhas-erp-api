# 📋 Análise do Feedback Frontend - Sistema Modular

> **Data:** 16/01/2026  
> **Status:** Analisando sugestões

---

## ✅ Sugestões Aceitas

| #  | Sugestão | Prioridade | Esforço | Status |
|----|----------|------------|---------|--------|
| 1  | `text_color` no status | 🔴 Alta | Baixo | Pendente |
| 2  | `confirm_button`, `confirm_variant` | 🔴 Alta | Baixo | Pendente |
| 3  | Separar `shortcut_modifier` | 🟡 Média | Baixo | Pendente |
| 4  | Estrutura completa `conditional_fields` | 🔴 Alta | Médio | Pendente |
| 5  | Campo `validations` | 🔴 Alta | Médio | Pendente |
| 6  | Config de `filters` | 🟡 Média | Médio | Pendente |
| 7  | Config de `table_columns` | 🟡 Média | Médio | Pendente |
| 8  | `bulk_actions` | 🟡 Média | Médio | Pendente |
| 9  | `row_actions` | 🟡 Média | Baixo | Pendente |
| 10 | Textos loading/error | 🟡 Média | Baixo | Pendente |
| 11 | Templates `notifications` | 🟢 Baixa | Baixo | Pendente |
| 12 | Config `stats_cards` | 🟢 Baixa | Médio | Pendente |
| 13 | PUT `/texts` | 🔴 Alta | Baixo | Pendente |
| 14 | PUT/POST `/actions` | 🟡 Média | Médio | Pendente |
| 15 | GET `/audit-log` | 🟢 Baixa | Médio | Pendente |
| 16 | `workflow_diagram` | 🟢 Baixa | Alto | Opcional |

---

## 📊 Resumo das Decisões

### Cache Strategy (Frontend decide)
✅ **Aceito**: React Query + webhook invalidation
- Backend implementará webhook quando módulo mudar

### Badges/Status
✅ **Aceito**: Adicionar `text_color` para badges customizados
```php
'color' => '#3B82F6',
'text_color' => 'white',
'badge_variant' => 'custom'
```

### Confirmações
✅ **Aceito**: Campos extras de confirmação
```php
'confirm_button' => 'Sim, Cancelar',
'cancel_button' => 'Não, Voltar',
'confirm_variant' => 'destructive'
```

### Shortcuts
✅ **Aceito**: Separar tecla e modificador
```php
'shortcut' => 'A',
'shortcut_modifier' => null  // ou 'ctrl', 'alt', 'shift'
```

### Transições
✅ **Aceito**: API já suporta edição via `PUT /transitions`
- Frontend fará UI de matriz spreadsheet

### Conditional Fields
✅ **Aceito**: Estrutura completa com validações
```php
[
    'type' => 'select',
    'label' => 'Motivo do Cancelamento',
    'placeholder' => 'Selecione o motivo',
    'required' => true,
    'options' => [...],
    'visible_when' => ['field' => 'value'],
    'validations' => ['min' => 1, 'max' => 500, 'pattern' => '...']
]
```

---

## 🔄 Novos Endpoints Sugeridos

| Método | Endpoint | Descrição | Prioridade |
|--------|----------|-----------|------------|
| PUT | `/modules/{id}/texts` | Editar só textos | 🔴 Alta |
| PUT | `/modules/{id}/actions/{actionId}` | Editar ação | 🟡 Média |
| POST | `/modules/{id}/actions` | Criar ação custom | 🟡 Média |
| GET | `/modules/{id}/audit-log` | Histórico mudanças | 🟢 Baixa |
| POST | `/webhook/module-changed` | Notificar frontend | 🟡 Média |

---

## 📈 Novas Configs Sugeridas

### 1. Filters (Prioridade Média)
```php
public function getFilters(): array
{
    return [
        'status' => [
            'type' => 'multi-select',
            'label' => 'Status',
            'options' => 'from_statuses'
        ],
        'date_range' => [
            'type' => 'date-range',
            'label' => 'Período',
            'presets' => ['today', 'week', 'month', 'custom']
        ]
    ];
}
```

### 2. Table Columns (Prioridade Média)
```php
public function getTableColumns(): array
{
    return [
        'default' => [
            ['key' => 'id', 'label' => '#', 'sortable' => true, 'width' => 80],
            ['key' => 'customer_name', 'label' => 'Cliente', 'sortable' => true],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge']
        ]
    ];
}
```

### 3. Bulk Actions (Prioridade Média)
```php
public function getBulkActions(): array
{
    return [
        'change_status' => [
            'label' => 'Alterar Status',
            'icon' => 'RefreshCw',
            'permission' => 'pedidos.bulk-update'
        ],
        'export' => [
            'label' => 'Exportar',
            'formats' => ['xlsx', 'pdf', 'csv']
        ]
    ];
}
```

### 4. Stats Cards (Prioridade Baixa)
```php
public function getStatsCards(): array
{
    return [
        'enabled' => true,
        'permission' => 'pedidos.view-stats',
        'cards' => [...]
    ];
}
```

---

## 📅 Cronograma Sugerido

### Semana 1: Alta Prioridade
- [ ] Campos extras status/actions (text_color, confirm_variant)
- [ ] Conditional fields com validations
- [ ] PUT `/texts` endpoint

### Semana 2: Média Prioridade
- [ ] Filters, table_columns, bulk_actions
- [ ] Row actions
- [ ] PUT/POST `/actions` endpoints

### Semana 3: Baixa Prioridade
- [ ] Textos loading/error
- [ ] Notifications templates
- [ ] Stats cards
- [ ] Audit log

### Futura: Opcional
- [ ] Workflow diagram
- [ ] Webhook de notificação

---

## 🎯 Próximo Passo

Preciso da sua aprovação para iniciar a implementação na ordem de prioridade acima.

**Quer que eu comece pela Semana 1?**
