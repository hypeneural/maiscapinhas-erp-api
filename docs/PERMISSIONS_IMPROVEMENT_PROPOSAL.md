# 🔐 Proposta: Sistema de Permissões Granular

> **Data**: 2026-01-16  
> **Status**: Proposta de Melhoria  
> **Autor**: Backend

---

## Problema Atual

O sistema atual tem uma implementação de permissões fragmentada:

1. **Super Admin** → Flag booleana no banco (`is_super_admin`)
2. **Roles de Loja** → Strings hardcoded em `StoreUser` (`admin`, `gerente`, `conferente`, `vendedor`)
3. **Role Fábrica** → Spatie Permission separado (`fabrica`)

### Problemas identificados:

```php
// Verificação atual espalhada nos controllers:
if (!$user->isSuperAdmin() && !$user->isGlobalAdmin()) {
    abort(403, ...);
}

// Hardcoded em múltiplos lugares:
StoreUser::ROLE_ADMIN = 'admin';
StoreUser::ROLE_GERENTE = 'gerente';
```

- ❌ Lógica de permissão espalhada em cada controller
- ❌ Difícil adicionar novos roles (ex: Estoquista)
- ❌ Não há como dar permissões específicas (ex: "pode editar formas de pagamento")
- ❌ Mistura de 3 sistemas diferentes

---

## Proposta: Sistema Unificado

### Hierarquia de Usuários

| ID | Role | Nível | Descrição |
|----|------|-------|-----------|
| 1 | `super_admin` | 100 | Acesso total ao sistema |
| 2 | `admin` | 90 | Administrador global |
| 3 | `fabrica` | 80 | Usuário da fábrica |
| 4 | `gerente` | 70 | Gerente de loja |
| 5 | `conferente` | 60 | Conferente de caixa |
| 6 | `estoquista` | 50 | Controle de estoque |
| 7 | `vendedor` | 40 | Vendedor padrão |

---

## Solução 1: Spatie Permission (Já instalado)

O Laravel já tem o **Spatie Permission** instalado. Podemos usar 100% dele.

### Vantagens:
- ✅ Já está instalado
- ✅ Suporta Roles e Permissions
- ✅ Middleware pronto
- ✅ Cache de permissões

### Implementação:

#### 1. Criar Roles

```php
// Seeder: RolesAndPermissionsSeeder.php

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

// Roles globais (sem contexto de loja)
Role::create(['name' => 'super_admin', 'guard_name' => 'sanctum']);
Role::create(['name' => 'admin', 'guard_name' => 'sanctum']);
Role::create(['name' => 'fabrica', 'guard_name' => 'sanctum']);
Role::create(['name' => 'gerente', 'guard_name' => 'sanctum']);
Role::create(['name' => 'conferente', 'guard_name' => 'sanctum']);
Role::create(['name' => 'estoquista', 'guard_name' => 'sanctum']);
Role::create(['name' => 'vendedor', 'guard_name' => 'sanctum']);
```

#### 2. Criar Permissions

```php
// Módulo: Formas de Pagamento
Permission::create(['name' => 'payment-methods.view', 'guard_name' => 'sanctum']);
Permission::create(['name' => 'payment-methods.create', 'guard_name' => 'sanctum']);
Permission::create(['name' => 'payment-methods.update', 'guard_name' => 'sanctum']);
Permission::create(['name' => 'payment-methods.delete', 'guard_name' => 'sanctum']);

// Módulo: Pedidos
Permission::create(['name' => 'pedidos.view', 'guard_name' => 'sanctum']);
Permission::create(['name' => 'pedidos.create', 'guard_name' => 'sanctum']);
Permission::create(['name' => 'pedidos.update', 'guard_name' => 'sanctum']);
Permission::create(['name' => 'pedidos.delete', 'guard_name' => 'sanctum']);
Permission::create(['name' => 'pedidos.status.update', 'guard_name' => 'sanctum']);
Permission::create(['name' => 'pedidos.bulk-status', 'guard_name' => 'sanctum']);

// Módulo: Usuários
Permission::create(['name' => 'users.view', 'guard_name' => 'sanctum']);
Permission::create(['name' => 'users.create', 'guard_name' => 'sanctum']);
Permission::create(['name' => 'users.update', 'guard_name' => 'sanctum']);
Permission::create(['name' => 'users.delete', 'guard_name' => 'sanctum']);

// ... outros módulos
```

#### 3. Atribuir Permissions aos Roles

```php
// Super Admin tem tudo
$superAdmin = Role::findByName('super_admin', 'sanctum');
$superAdmin->givePermissionTo(Permission::all());

// Admin tem quase tudo
$admin = Role::findByName('admin', 'sanctum');
$admin->givePermissionTo([
    'payment-methods.view', 'payment-methods.create', 'payment-methods.update', 'payment-methods.delete',
    'pedidos.view', 'pedidos.create', 'pedidos.update', 'pedidos.delete', 'pedidos.bulk-status',
    'users.view', 'users.create', 'users.update',
]);

// Vendedor tem acesso limitado
$vendedor = Role::findByName('vendedor', 'sanctum');
$vendedor->givePermissionTo([
    'payment-methods.view',
    'pedidos.view', 'pedidos.create', 'pedidos.update', 'pedidos.status.update',
]);
```

#### 4. Uso nos Controllers

```php
// ANTES (ruim - lógica espalhada)
private function authorizeAdmin(Request $request): void
{
    $user = $request->user();
    if (!$user->isSuperAdmin() && !$user->isGlobalAdmin()) {
        abort(403, 'Apenas administradores...');
    }
}

// DEPOIS (bom - centralizado)
public function store(StorePaymentMethodRequest $request): JsonResponse
{
    $this->authorize('payment-methods.create'); // Gate automático
    
    // ou via middleware na rota:
    // Route::post(...)->middleware('permission:payment-methods.create');
    
    $paymentMethod = PaymentMethod::create($request->validated());
    ...
}
```

#### 5. Middleware nas Rotas

```php
// api_v1.php

Route::apiResource('payment-methods', PaymentMethodController::class)
    ->middleware([
        'permission:payment-methods.view' => ['index', 'show'],
        'permission:payment-methods.create' => ['store'],
        'permission:payment-methods.update' => ['update'],
        'permission:payment-methods.delete' => ['destroy'],
    ]);

// Ou de forma mais simples:
Route::middleware('role:admin|super_admin')->group(function () {
    Route::post('payment-methods', [PaymentMethodController::class, 'store']);
    Route::patch('payment-methods/{payment_method}', [PaymentMethodController::class, 'update']);
    Route::delete('payment-methods/{payment_method}', [PaymentMethodController::class, 'destroy']);
});
```

---

## Solução 2: Criar CRUD de Roles/Permissions

### Endpoints Sugeridos

```
# Roles
GET    /api/v1/admin/roles              # Listar roles
POST   /api/v1/admin/roles              # Criar role
GET    /api/v1/admin/roles/{id}         # Detalhes do role
PATCH  /api/v1/admin/roles/{id}         # Atualizar role
DELETE /api/v1/admin/roles/{id}         # Excluir role
POST   /api/v1/admin/roles/{id}/permissions  # Atribuir permissions

# Permissions
GET    /api/v1/admin/permissions        # Listar permissions
POST   /api/v1/admin/permissions        # Criar permission
DELETE /api/v1/admin/permissions/{id}   # Excluir permission

# User Roles
POST   /api/v1/admin/users/{id}/roles   # Atribuir roles ao usuário
DELETE /api/v1/admin/users/{id}/roles   # Remover roles do usuário
```

### Modelo de Dados

```
┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐
│     users       │       │   model_has_    │       │     roles       │
│─────────────────│       │     roles       │       │─────────────────│
│ id              │◄──────┤ model_id        │──────►│ id              │
│ name            │       │ role_id         │       │ name            │
│ email           │       │ model_type      │       │ guard_name      │
│ is_super_admin  │       └─────────────────┘       │ level (novo)    │
└─────────────────┘                                 └─────────────────┘
                                                            │
                                                            ▼
                          ┌─────────────────┐       ┌─────────────────┐
                          │  role_has_      │       │   permissions   │
                          │  permissions    │       │─────────────────│
                          │─────────────────│──────►│ id              │
                          │ role_id         │       │ name            │
                          │ permission_id   │       │ guard_name      │
                          └─────────────────┘       │ module (novo)   │
                                                    └─────────────────┘
```

---

## Matriz de Permissões Proposta

### Legenda:
- ✅ Pode fazer
- 👁️ Apenas visualizar
- ❌ Sem acesso
- 🏪 Apenas em sua(s) loja(s)

| Módulo | Super Admin | Admin | Fábrica | Gerente | Conferente | Estoquista | Vendedor |
|--------|-------------|-------|---------|---------|------------|------------|----------|
| **Formas Pagamento** | ✅ | ✅ | ❌ | 👁️ | 👁️ | 👁️ | 👁️ |
| **Usuários** | ✅ | ✅ 🏪 | ❌ | 👁️ 🏪 | ❌ | ❌ | ❌ |
| **Lojas** | ✅ | ✅ 🏪 | ❌ | 👁️ 🏪 | ❌ | ❌ | ❌ |
| **Pedidos Simples** | ✅ | ✅ | ❌ | ✅ 🏪 | ✅ 🏪 | 👁️ 🏪 | ✅ 🏪 |
| **Capas Personalizadas** | ✅ | ✅ | 👁️ | ✅ 🏪 | ✅ 🏪 | 👁️ 🏪 | ✅ 🏪 |
| **Produção (Carrinho)** | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Portal Fábrica** | ✅ | 👁️ | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Fechamento Caixa** | ✅ | ✅ | ❌ | ✅ 🏪 | ✅ 🏪 | ❌ | 👁️ 🏪 |
| **Relatórios** | ✅ | ✅ | ❌ | ✅ 🏪 | 👁️ 🏪 | ❌ | ❌ |
| **Estoque** | ✅ | ✅ | ❌ | ✅ 🏪 | ❌ | ✅ 🏪 | 👁️ 🏪 |
| **WhatsApp Instances** | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Audit Logs** | ✅ | 👁️ | ❌ | ❌ | ❌ | ❌ | ❌ |

---

## Plano de Migração

### Fase 1: Preparação (sem breaking changes)
1. ✅ Criar seeder de roles e permissions
2. ✅ Migrar `is_super_admin` → role `super_admin`
3. ✅ Migrar `StoreUser->role` → Spatie roles com contexto de loja
4. ✅ Manter métodos legados funcionando (`isSuperAdmin()`, `isGlobalAdmin()`)

### Fase 2: Implementação Gradual
1. 🔄 Adicionar middleware de permission em novos endpoints
2. 🔄 Migrar controllers antigos gradualmente
3. 🔄 Criar CRUD de roles/permissions para o frontend admin

### Fase 3: Cleanup
1. ⏳ Remover flag `is_super_admin` do banco
2. ⏳ Remover constantes de roles do `StoreUser`
3. ⏳ Remover métodos legados do `User`

---

## Benefícios

| Aspecto | Antes | Depois |
|---------|-------|--------|
| Adicionar novo role | Editar código em múltiplos lugares | Um INSERT no banco |
| Verificar permissão | `if ($user->isSuperAdmin() \|\| ...)` | `$this->authorize('module.action')` |
| Dar permissão especial | Impossível | `$user->givePermissionTo('...')` |
| Frontend saber permissões | Calcular baseado em flags | Array de permissions no `/me` |
| Auditoria | Difícil | Built-in no Spatie |

---

## Próximos Passos

Se aprovado, posso implementar:

1. [ ] Criar `RolesAndPermissionsSeeder` com todos os roles/permissions
2. [ ] Criar migration para adicionar coluna `level` aos roles
3. [ ] Atualizar endpoint `/me` para retornar permissions
4. [ ] Criar `RoleController` e `PermissionController` para CRUD
5. [ ] Documentar todos os endpoints

---

## Exemplo: Response do `/me` Melhorado

```json
{
  "data": {
    "user": {
      "id": 1,
      "name": "João Silva",
      "email": "joao@loja.com",
      "roles": ["gerente"],
      "permissions": [
        "payment-methods.view",
        "pedidos.view",
        "pedidos.create",
        "pedidos.update",
        "pedidos.status.update",
        "capas.view",
        "capas.create",
        "caixa.approve"
      ]
    },
    "stores": [
      { "id": 1, "name": "Loja Centro", "permissions": ["*"] }
    ]
  }
}
```

---

## Decisão Necessária

> **Pergunta para você:**  
> Quer que eu implemente a **Solução 1** (usar Spatie completo) ou a **Solução 2** (CRUD de roles/permissions via API)?
> 
> A Solução 1 é mais rápida de implementar.  
> A Solução 2 permite gerenciar tudo pelo frontend.
>
> Posso também fazer as duas: usar Spatie internamente e criar CRUD para gerenciar via API.

---

*Aguardo seu feedback! 🚀*
