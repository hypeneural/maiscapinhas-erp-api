# FAQ Backend - Permissões e Módulos

Respostas para as dúvidas do time de frontend sobre permissões e exibição de módulos.

---

## A. Integração Permissões + Módulos

### A1. As permissões dos módulos são automaticamente aplicadas aos roles?

**NÃO.** As permissões definidas nos módulos (`getPermissions()`) servem apenas como **definições de configuração** e metadados para o frontend.

**O que precisa ser feito:**
1. Após criar um módulo, usar o endpoint `POST /api/v1/admin/roles/{role}/permissions` para atribuir permissões aos roles
2. Ou usar `POST /api/v1/admin/permissions/bulk` para criar as permissões no Spatie
3. Depois atribuir via `PUT /api/v1/admin/roles/{role}/permissions`

As permissões do módulo são **declarativas** - o backend usa o Spatie Permission para controle real de acesso.

---

### A2. O `/me` inclui permissões de módulos ou só as do Spatie?

**Ambas.** O endpoint `/me` retorna:

```json
{
  "user": { ... },
  "permissions": ["pedidos.view", "capas.create", "screen.dashboard", ...]
}
```

O campo `permissions` inclui:
1. ✅ **Permissões de roles** (Spatie) - vindas dos roles atribuídos ao usuário
2. ✅ **Screen permissions** - mapeadas automaticamente baseadas nos roles
3. ✅ **User overrides** - permissões concedidas diretamente ao usuário (temporárias ou permanentes)

**Código relevante:** `MeController::resolveUserPermissions()`

---

### A3. A `transition_role_matrix` é validada no backend?

**SIM**, mas depende de quem chama.

**Situação atual:**
- O método `canUserTransition($fromStatus, $toStatus, $userRoles)` existe no `BaseModule`
- Os controllers de Pedido e Capa **NÃO** estão usando essa validação ainda
- Atualmente eles usam apenas `authorizeAccess()` que verifica se o usuário é dono do pedido ou admin

**Para implementar validação da matriz:**
```php
// No PedidoController::updateStatus()
$module = ModuleRegistry::getInstance()->get('pedidos-simples');
if (!$module->canUserTransition($oldStatus, $newStatus, $user->getRoleNames()->toArray())) {
    abort(403, 'Você não tem permissão para esta transição de status.');
}
```

> ⚠️ **AÇÃO NECESSÁRIA**: Se quiserem validação da matriz, solicitar implementação no backend.

---

## B. Endpoints de Dados por Usuário

### B1. `/pedidos` e `/capas-personalizadas` filtram por loja automaticamente?

**DEPENDE do tipo de usuário:**

| Tipo Usuário | Comportamento |
|--------------|---------------|
| **Super Admin / Global Admin** | Vê **TODOS** os pedidos/capas de todas as lojas |
| **Vendedor/Gerente/Conferente** | Vê **APENAS** pedidos que ele próprio criou (`user_id`) |

**Código:**
```php
// PedidoController::index()
if (!$this->isAdmin($request)) {
    $query->forUser($request->user()->id); // Filtra por user_id
}
```

**Se precisar filtrar por loja:**
- Admin pode usar query param: `?store_id=1`
- Não-admin é filtrado automaticamente pelo `user_id`

---

### B2. Usuário fábrica consegue ver todos os pedidos/capas?

**NÃO diretamente nos endpoints `/pedidos` e `/capas-personalizadas`.**

O usuário `fabrica` acessa dados pelo módulo de **Produção**:
- `GET /api/v1/producao/pedidos` - Pedidos enviados para fábrica (status 6+)
- `GET /api/v1/producao/capas` - Capas enviadas para produção

O filtro de fábrica acontece via **status** (apenas itens em produção), não via `store_id`.

---

### B3. Super Admin vê dados de todas as lojas automaticamente?

**SIM.** Super Admin e Global Admin:
- Não têm filtro de `store_id` ou `user_id` aplicado
- Veem todos os registros automaticamente
- Podem opcionalmente filtrar passando `?store_id=X`

---

## C. Matriz de Transições

### C1. Onde está definido quais status cada role pode mudar?

**No próprio módulo**, em `getTransitionRoleMatrix()`:

```php
// PedidosSimplesModule.php
public function getTransitionRoleMatrix(): array
{
    return [
        1 => [  // De "Solicitado"
            2 => ['vendedor', 'admin', 'gerente'],  // Para "Em Produção"
            7 => ['admin'],                          // Para "Cancelado"
        ],
        // ...
    ];
}
```

O frontend pode obter isso via:
```
GET /api/v1/admin/modules/pedidos-simples/full
→ transition_role_matrix
```

---

### C2. A matriz está sendo validada no controller Laravel?

**NÃO atualmente.** Os controllers (`PedidoController`, `CapaPersonalizadaController`) fazem apenas:
1. Verificar se usuário tem acesso ao registro (dono ou admin)
2. Chamar o service para atualizar status

**A matriz serve atualmente para:**
- Frontend exibir quais botões de transição mostrar
- Documentação de regras de negócio

**Se quiserem validação stricta no backend**, é necessário adicionar a chamada ao `canUserTransition()` nos controllers.

---

## D. Sincronização de Roles

### D1. Quando módulo é ativado/desativado, permissões são ajustadas?

**NÃO automaticamente.** Os lifecycle hooks:
```php
public function onActivate(int $storeId): void { }
public function onDeactivate(int $storeId): void { }
```

Estão **vazios** na implementação atual. As permissões precisam ser gerenciadas manualmente via:
- `POST /api/v1/admin/roles/{role}/permissions`
- `POST /api/v1/admin/stores/{store}/permission-overrides`

---

### D2. O role `fabrica` existe como?

**Global via Spatie.** O role `fabrica` é um role do Spatie Permission, não do pivot `store_user`.

```php
// Role.php
public const FABRICA = 'fabrica';
public const LEVEL_FABRICA = 80;
```

O usuário de fábrica:
- Tem o role `fabrica` atribuído globalmente
- Não está vinculado a uma loja específica
- Acessa pedidos/capas por **status** (em produção), não por loja

---

## E. Telas por Role ("Screen Suggestions")

### E1. As screens do módulo controlam acesso a rotas?

**NÃO como middleware.** As screens são apenas **metadados para o frontend**:

```php
public function getScreens(): array
{
    return [
        ['name' => 'pedidos.list', 'display_name' => 'Lista de Pedidos', 'path' => '/pedidos'],
        ['name' => 'pedidos.create', 'display_name' => 'Novo Pedido', 'path' => '/pedidos/new'],
    ];
}
```

O frontend deve:
1. Obter screens do módulo
2. Verificar se usuário tem a permission correspondente
3. Exibir/esconder menu items e rotas

**O backend NÃO valida acesso a rotas baseado em screens** - isso é controle do frontend.

---

### E2. Existe endpoint que retorna todas as telas que o usuário pode acessar?

**NÃO existe um endpoint consolidado.** Mas dá para construir no frontend:

```typescript
// Frontend: construir telas acessíveis
const { permissions, stores } = await api.get('/me');
const modules = await api.get('/admin/modules');

const accessibleScreens = [];
for (const module of modules) {
  const fullModule = await api.get(`/admin/modules/${module.id}/full`);
  for (const screen of fullModule.screens) {
    // Padrão: screen.name é uma permission
    if (permissions.includes(screen.name)) {
      accessibleScreens.push(screen);
    }
  }
}
```

**Alternativa: criar endpoint no backend:**
```
GET /api/v1/me/screens
```

> 💡 **SUGESTÃO**: Se o frontend precisar, posso criar esse endpoint consolidado.

---

## Resumo de Ações Pendentes

| Item | Status | Nota |
|------|--------|------|
| Validação de `transition_role_matrix` nos controllers | 🟢 Implementado | Retorna 403 com detalhes se role não autorizado |
| Sincronização de permissões ao ativar/desativar módulo | 🔴 Não implementado | Implementar se necessário |
| Endpoint `/me/screens` consolidado | 🟢 Implementado | `GET /api/v1/me/screens` |
| Screen permissions como middleware | 🟡 Apenas metadado | Manter como está (controle FE) |

---

## Resumo Visual

```
┌─────────────────────────────────────────────────────────────────┐
│                        /me endpoint                              │
├─────────────────────────────────────────────────────────────────┤
│  user: { id, roles, is_super_admin, has_fabrica_access }        │
│  permissions: [...roles perms, ...overrides, ...screens]        │
│  stores: [{ id, name, role }]                                   │
│  temporary_permissions: [...]                                    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Frontend Decision                             │
├─────────────────────────────────────────────────────────────────┤
│  1. Checar permissions[] para habilitar/desabilitar features    │
│  2. Checar roles[] para determinar navigation                   │
│  3. Checar is_super_admin para mostrar admin panel              │
│  4. Usar stores[] para seletor de loja (se aplicável)           │
│  5. Fetch /modules/{id}/full para transitions e screens         │
└─────────────────────────────────────────────────────────────────┘
```
