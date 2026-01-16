# 🧩 Sistema Modular - Arquitetura

> **Data:** 16/01/2026  
> **Objetivo:** Criar sistema de módulos como WordPress para fácil extensão

---

## 1. Conceito

Cada **módulo** é um pacote completo e isolado contendo:
- Configuração do módulo
- Status e transições
- Permissions próprias
- Campos condicionais
- Automações (jobs)

O **Super Admin** pode:
- Ativar/desativar módulos por loja
- Configurar status e transições
- Gerenciar permissions do módulo
- Ver logs de automações

---

## 2. Estrutura de Arquivos

```
app/Modules/
├── ModuleRegistry.php           # Registro central de módulos
├── ModuleInterface.php          # Contrato que todo módulo implementa
├── BaseModule.php               # Classe base com comportamentos comuns
│
├── PedidosSimples/              # Módulo de Pedidos
│   ├── PedidosSimplesModule.php # Definição principal
│   ├── Config/
│   │   └── module.php           # Configurações
│   ├── Enums/
│   │   └── PedidoStatus.php     # Status (já existe, migrar)
│   ├── Policies/
│   │   └── StatusTransitionPolicy.php
│   ├── Jobs/
│   │   └── AutoCancelAfterDays.php
│   └── Permissions/
│       └── permissions.php      # Definição de permissions
│
├── CapasPersonalizadas/         # Módulo de Capas
│   ├── CapasModule.php
│   ├── Config/
│   │   └── module.php
│   ├── Enums/
│   │   └── CapaStatus.php
│   ├── Policies/
│   │   └── StatusTransitionPolicy.php
│   └── Permissions/
│       └── permissions.php
│
└── Producao/                    # Módulo de Produção/Fábrica
    ├── ProducaoModule.php
    └── ...
```

---

## 3. Interface do Módulo

```php
<?php

namespace App\Modules;

interface ModuleInterface
{
    // Identificação
    public function getId(): string;           // 'pedidos-simples'
    public function getName(): string;         // 'Pedidos Simples'
    public function getDescription(): string;
    public function getVersion(): string;
    public function getIcon(): string;         // 'FileCheck'
    
    // Dependências
    public function getDependencies(): array;  // ['customers']
    
    // Status/Workflow
    public function getStatuses(): array;
    public function getStatusTransitions(): array;
    public function getStatusRoleMatrix(): array;
    
    // Permissions
    public function getPermissions(): array;
    public function getScreens(): array;
    
    // Campos condicionais
    public function getConditionalFields(): array;
    
    // Automações
    public function getAutomations(): array;
    
    // Hooks
    public function onInstall(): void;
    public function onUninstall(): void;
    public function onActivate(int $storeId): void;
    public function onDeactivate(int $storeId): void;
}
```

---

## 4. Exemplo: Módulo Pedidos Simples

```php
<?php

namespace App\Modules\PedidosSimples;

use App\Modules\BaseModule;
use App\Modules\ModuleInterface;

class PedidosSimplesModule extends BaseModule implements ModuleInterface
{
    public function getId(): string
    {
        return 'pedidos-simples';
    }

    public function getName(): string
    {
        return 'Pedidos Simples';
    }

    public function getIcon(): string
    {
        return 'FileCheck';
    }

    public function getStatuses(): array
    {
        return [
            1 => ['name' => 'solicitado', 'label' => 'Solicitado', 'color' => 'blue', 'final' => false],
            2 => ['name' => 'indisponivel', 'label' => 'Produto Indisponível', 'color' => 'red', 'final' => false],
            3 => ['name' => 'disponivel', 'label' => 'Disponível na Loja', 'color' => 'yellow', 'final' => false],
            4 => ['name' => 'aguardando', 'label' => 'Aguardando Cliente', 'color' => 'purple', 'final' => false],
            5 => ['name' => 'concluido', 'label' => 'Venda Concluída', 'color' => 'green', 'final' => true],
            6 => ['name' => 'cancelado', 'label' => 'Cancelado', 'color' => 'gray', 'final' => true],
        ];
    }

    public function getStatusTransitions(): array
    {
        // De => [Para possíveis]
        return [
            1 => [3, 6],        // solicitado → disponível, cancelado
            3 => [4, 6],        // disponível → aguardando, cancelado
            4 => [5, 6],        // aguardando → concluído, cancelado
        ];
    }

    public function getStatusRoleMatrix(): array
    {
        // [de][para] => roles permitidos
        return [
            1 => [
                3 => ['admin', 'gerente', 'super-admin'],  // → disponível
                6 => ['vendedor', 'admin', 'gerente'],     // → cancelado
            ],
            3 => [
                4 => ['vendedor', 'admin', 'gerente'],     // → aguardando
                6 => ['vendedor', 'admin', 'gerente'],     // → cancelado
            ],
            4 => [
                5 => ['vendedor', 'admin', 'gerente'],     // → concluído
                6 => ['vendedor', 'admin', 'gerente'],     // → cancelado
            ],
        ];
    }

    public function getPermissions(): array
    {
        return [
            // Abilities
            ['name' => 'pedidos.view', 'type' => 'ability'],
            ['name' => 'pedidos.view-all', 'type' => 'ability'],
            ['name' => 'pedidos.create', 'type' => 'ability'],
            ['name' => 'pedidos.update', 'type' => 'ability'],
            ['name' => 'pedidos.cancel', 'type' => 'ability'],
            ['name' => 'pedidos.status.to-disponivel', 'type' => 'ability'],
            ['name' => 'pedidos.status.to-aguardando', 'type' => 'ability'],
            ['name' => 'pedidos.status.to-concluido', 'type' => 'ability'],
        ];
    }

    public function getConditionalFields(): array
    {
        return [
            'cancelado' => [
                'cancelation_reason' => ['required' => true, 'type' => 'select'],
                'cancelation_notes' => ['required' => false, 'type' => 'textarea'],
            ],
            'concluido' => [
                'payment_amount' => ['required' => true, 'type' => 'money'],
                'payment_date' => ['required' => true, 'type' => 'date'],
                'payment_method_id' => ['required' => true, 'type' => 'select'],
            ],
        ];
    }

    public function getAutomations(): array
    {
        return [
            [
                'name' => 'auto_cancel_disponivel',
                'trigger' => 'status_unchanged_days',
                'config' => [
                    'status' => 'disponivel',
                    'days' => 20,
                    'action' => 'change_status',
                    'new_status' => 'cancelado',
                    'reason' => 'Inércia do vendedor',
                ],
            ],
            [
                'name' => 'auto_cancel_aguardando',
                'trigger' => 'status_unchanged_days',
                'config' => [
                    'status' => 'aguardando',
                    'days' => 20,
                    'action' => 'change_status',
                    'new_status' => 'cancelado',
                    'reason' => 'Não comparecimento do cliente',
                ],
            ],
        ];
    }
}
```

---

## 5. Tabelas do Banco

### modules

```sql
CREATE TABLE modules (
    id VARCHAR(50) PRIMARY KEY,        -- 'pedidos-simples'
    name VARCHAR(100) NOT NULL,
    description TEXT,
    version VARCHAR(20) NOT NULL,
    icon VARCHAR(50),
    is_core BOOLEAN DEFAULT FALSE,     -- Módulos do sistema
    is_active BOOLEAN DEFAULT TRUE,
    installed_at TIMESTAMP,
    config JSON,                       -- Configurações custom
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### module_store

```sql
CREATE TABLE module_store (
    id BIGINT PRIMARY KEY,
    module_id VARCHAR(50) NOT NULL,
    store_id BIGINT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    config JSON,                       -- Config específica da loja
    activated_at TIMESTAMP,
    UNIQUE(module_id, store_id)
);
```

### module_permissions

```sql
CREATE TABLE module_permissions (
    id BIGINT PRIMARY KEY,
    module_id VARCHAR(50) NOT NULL,
    permission_id BIGINT NOT NULL,     -- FK para permissions
    is_required BOOLEAN DEFAULT FALSE, -- Obrigatória para o módulo funcionar
    UNIQUE(module_id, permission_id)
);
```

---

## 6. API Super Admin

### Módulos

| Método | Rota | Descrição |
|--------|------|-----------|
| GET | `/admin/modules` | Listar módulos |
| GET | `/admin/modules/{id}` | Detalhes do módulo |
| POST | `/admin/modules/{id}/activate` | Ativar globalmente |
| POST | `/admin/modules/{id}/deactivate` | Desativar |

### Módulos por Loja

| Método | Rota | Descrição |
|--------|------|-----------|
| GET | `/admin/stores/{id}/modules` | Módulos da loja |
| POST | `/admin/stores/{id}/modules/{moduleId}/activate` | Ativar na loja |
| POST | `/admin/stores/{id}/modules/{moduleId}/deactivate` | Desativar |
| PUT | `/admin/stores/{id}/modules/{moduleId}/config` | Configurar |

### Status/Workflow do Módulo

| Método | Rota | Descrição |
|--------|------|-----------|
| GET | `/admin/modules/{id}/statuses` | Listar status |
| GET | `/admin/modules/{id}/transitions` | Listar transições |
| PUT | `/admin/modules/{id}/transitions` | Editar transições* |

> *Permitir Super Admin editar quem pode fazer qual transição

---

## 7. Fluxo de Verificação de Status

```php
class StatusTransitionService
{
    public function canTransition(
        User $user,
        string $moduleId,
        int $fromStatus,
        int $toStatus,
        ?int $storeId = null
    ): bool {
        // 1. Super admin bypassa tudo
        if ($user->is_super_admin) {
            return true;
        }
        
        // 2. Verificar se transição existe no módulo
        $module = ModuleRegistry::get($moduleId);
        $transitions = $module->getStatusTransitions();
        
        if (!isset($transitions[$fromStatus]) || 
            !in_array($toStatus, $transitions[$fromStatus])) {
            return false;
        }
        
        // 3. Verificar se role do usuário pode fazer esta transição
        $matrix = $module->getStatusRoleMatrix();
        $allowedRoles = $matrix[$fromStatus][$toStatus] ?? [];
        
        return $user->hasAnyRole($allowedRoles, $storeId);
    }
}
```

---

## 8. UI Super Admin: Gestão de Módulos

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  GESTÃO DE MÓDULOS                                            [+ Instalar]  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │  📦 Pedidos Simples                    v1.0.0    [Configurar] [🔄]  │   │
│  │  Gerenciamento de pedidos de encomenda                              │   │
│  │  ✅ Ativo em 5 lojas                                                │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │  🎨 Capas Personalizadas              v1.2.0    [Configurar] [🔄]  │   │
│  │  Capas com foto do cliente + produção                               │   │
│  │  ✅ Ativo em 5 lojas                                                │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │  🏭 Portal Fábrica                    v1.0.0    [Configurar] [🔄]  │   │
│  │  Portal de produção para fábrica                                    │   │
│  │  ✅ Ativo globalmente                                               │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Tela de Configuração do Módulo

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  CONFIGURAR: Pedidos Simples                                   [← Voltar]  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  [Geral] [Status] [Transições] [Permissions] [Automações]                   │
│  ═══════════════════════════════════════════════════════════════════════   │
│                                                                             │
│  STATUS DO MÓDULO:                                                          │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │ # │ Nome              │ Cor     │ Final │ Ações                      │  │
│  │───┼───────────────────┼─────────┼───────┼────────────────────────────│  │
│  │ 1 │ Solicitado        │ 🔵 blue │ ❌    │ [Editar]                  │  │
│  │ 3 │ Disponível Loja   │ 🟡 yellow│ ❌   │ [Editar]                  │  │
│  │ 4 │ Aguardando        │ 🟣 purple│ ❌   │ [Editar]                  │  │
│  │ 5 │ Concluído         │ 🟢 green │ ✅   │ [Editar]                  │  │
│  │ 6 │ Cancelado         │ ⚫ gray  │ ✅   │ [Editar]                  │  │
│  └──────────────────────────────────────────────────────────────────────┘  │
│                                                                             │
│  MATRIZ DE TRANSIÇÕES:                                                      │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │ De → Para        │ Vendedor │ Gerente │ Admin │ Super               │  │
│  │───────────────────┼──────────┼─────────┼───────┼─────────────────────│  │
│  │ Solicit→Disponív │ ❌       │ ✅      │ ✅    │ ✅                  │  │
│  │ Solicit→Cancelad │ ✅       │ ✅      │ ✅    │ ✅                  │  │
│  │ Disponí→Aguardan │ ✅       │ ✅      │ ✅    │ ✅                  │  │
│  │ Aguarda→Concluíd │ ✅       │ ✅      │ ✅    │ ✅                  │  │
│  └──────────────────────────────────────────────────────────────────────┘  │
│                                                       [Salvar Alterações]   │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 9. Benefícios

| Benefício | Descrição |
|-----------|-----------|
| **Isolamento** | Cada módulo é independente |
| **Reutilização** | Código compartilhado via BaseModule |
| **Flexibilidade** | Super Admin configura transições |
| **Escalabilidade** | Fácil adicionar novos módulos |
| **Manutenção** | Bugs isolados por módulo |

---

## 10. Próximos Passos

1. [ ] Criar `ModuleInterface` e `BaseModule`
2. [ ] Migrar `PedidoStatus` para módulo
3. [ ] Migrar `CapaPersonalizadaStatus` para módulo
4. [ ] Criar tabelas `modules`, `module_store`
5. [ ] API de gestão de módulos
6. [ ] UI Super Admin
