# MaisCapinhas ERP API

Sistema ERP completo para gestão de vendas, metas, bônus, comissões e auditoria de lojas MaisCapinhas.

## 🚀 Funcionalidades Principais

| Módulo | Descrição |
|--------|-----------|
| **Autenticação** | Login/Logout com Sanctum, multi-dispositivo, auditoria de acessos |
| **Multi-Loja** | RBAC por loja com roles (admin, gerente, conferente, vendedor) |
| **Vendas** | CRUD completo, importação, filtros por período/vendedor/loja |
| **Fechamento de Caixa** | Workflow draft→submitted→approved, divergências, justificativas |
| **Bônus Diário** | Motor automático com faixas configuráveis |
| **Comissão Mensal** | Motor por atingimento de meta com tiers progressivos |
| **Metas & Splits** | Metas por loja com distribuição percentual por vendedor |
| **Dashboards** | KPIs personalizados por role (vendedor, conferente, admin) |
| **Relatórios** | Performance de loja, integridade de caixa, projeções YoY |
| **Auditoria** | Logs completos com request_id, IP, user-agent |
| **People Analytics** | Integração FastAPI para KPIs de turno |

---

## 📋 Stack Tecnológica

| Camada | Tecnologia |
|--------|------------|
| **Framework** | Laravel 12 |
| **Autenticação** | Laravel Sanctum (Bearer + SPA Cookie) |
| **Banco de Dados** | MySQL 8.0 |
| **Queue** | Redis / Sync |
| **Documentação** | Scribe (OpenAPI 3.0 + Postman) |
| **Auditoria** | Spatie ActivityLog + Custom AuditLogger |
| **Testes** | Pest PHP |
| **HTTP Client** | Guzzle (People Analytics) |

---

## 🏁 Quick Start

```bash
# Instalar dependências
composer install

# Copiar .env e configurar banco
cp .env.example .env
php artisan key:generate

# Rodar migrations + seeders
php artisan migrate:fresh --seed

# Iniciar servidor
php artisan serve
```

---

## 📚 Documentação da API

A API possui documentação interativa gerada via [Scribe](https://scribe.knuckles.wtf/).

```bash
# Gerar documentação
php artisan scribe:generate
```

| Arquivo | Descrição |
|---------|-----------|
| `public/docs/index.html` | Documentação HTML interativa |
| `public/docs/collection.json` | Postman Collection v2.1 |
| `public/docs/openapi.yaml` | OpenAPI 3.0 Spec |

**URLs locais:**
- Laragon: `http://maiscapinhas-erp-api.test/docs`
- Artisan: `http://localhost:8000/docs`

---

## 🔐 Autenticação

A API usa **Laravel Sanctum** com Bearer Token.

```http
POST /api/v1/auth/login
Content-Type: application/json

{"email": "admin@maiscapinhas.com.br", "password": "password"}
```

**Response:**
```json
{
  "data": {
    "token": "1|abc123xyz...",
    "token_type": "Bearer",
    "user": { "id": 1, "name": "Admin", "email": "admin@maiscapinhas.com.br" }
  },
  "meta": { "request_id": "uuid-aqui", "timestamp": "2026-01-07T12:00:00Z" }
}
```

Use o header: `Authorization: Bearer {token}`

### Usuários de Teste

| Email | Role | Lojas |
|-------|------|-------|
| admin@maiscapinhas.com.br | Admin | Todas |
| carlos.gerente@maiscapinhas.com.br | Gerente | Tijucas, Itapema |
| maria.gerente@maiscapinhas.com.br | Gerente | Bombinhas |
| ana.conferente@maiscapinhas.com.br | Conferente | Tijucas, Itapema |
| joao.vendedor@maiscapinhas.com.br | Vendedor | Tijucas |

**Senha padrão:** `password`

---

## 📡 Endpoints da API

### Públicos

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/api/v1/health` | Health check |
| GET | `/api/v1/version` | Versão da API |
| POST | `/api/v1/auth/login` | Login e obter token |

### Autenticação

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| POST | `/auth/logout` | Logout (revogar token atual) |
| POST | `/auth/logout-all` | Revogar todos os tokens |
| GET | `/me` | Perfil do usuário + lojas |

### Vendas (CRUD)

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/sales` | Listar vendas (filtros: store_id, seller_id, from, to) |
| POST | `/sales` | Criar venda manual |
| GET | `/sales/{id}` | Ver detalhes |
| PUT | `/sales/{id}` | Atualizar (gerente+) |
| DELETE | `/sales/{id}` | Excluir (admin) |

### Lojas

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/stores` | Listar lojas do usuário |
| GET | `/stores/{id}` | Detalhes da loja |

### Caixa

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/cash/shifts` | Listar turnos |
| POST | `/cash/shifts` | Criar turno |
| GET | `/cash/shifts/{id}` | Ver turno |
| GET | `/cash/closings/{shift}` | Ver fechamento |
| POST | `/cash/closings/{shift}/submit` | Enviar fechamento |
| POST | `/cash/closings/{shift}/approve` | Aprovar (conferente+) |
| POST | `/cash/closings/{shift}/reject` | Rejeitar + motivo |

### Regras (admin/gerente)

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/rules/bonus` | Listar regras de bônus |
| POST | `/rules/bonus` | Criar regra |
| GET | `/rules/bonus/{id}` | Ver regra |
| PUT | `/rules/bonus/{id}` | Atualizar regra |
| DELETE | `/rules/bonus/{id}` | Excluir regra |
| GET | `/rules/commission` | Listar regras de comissão |
| POST | `/rules/commission` | Criar regra |
| PUT | `/rules/commission/{id}` | Atualizar regra |

### Metas (admin/gerente)

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/goals/monthly` | Listar metas mensais |
| POST | `/goals/monthly` | Criar meta |
| GET | `/goals/monthly/{id}` | Ver meta |
| PUT | `/goals/monthly/{id}` | Atualizar meta |
| PUT | `/goals/monthly/{id}/splits` | Definir splits (soma=100%) |

### Extrato Financeiro

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/finance/bonus` | Ledger de bônus diário |
| GET | `/finance/commission` | Ledger de comissão mensal |

### Dashboards

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/dashboard/vendedor` | Dashboard do vendedor com gamificação |
| GET | `/dashboard/conferente` | Dashboard do conferente |
| GET | `/dashboard/admin` | Dashboard consolidado |

### Relatórios Gerenciais

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/reports/store-performance` | Performance da loja (meta, YoY, projeções) |
| GET | `/reports/consolidated` | Performance multi-loja |
| GET | `/reports/cash-integrity` | Integridade de caixa (% quebra, divergências) |

### People Analytics

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/analytics/people/shift` | KPIs por turno |
| POST | `/analytics/people/shift` | Inserir KPI manual |

### Admin - Usuários

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/admin/users` | Listar usuários |
| POST | `/admin/users` | Criar usuário |
| GET | `/admin/users/{id}` | Ver usuário |
| PUT | `/admin/users/{id}` | Atualizar usuário |
| DELETE | `/admin/users/{id}` | Desativar usuário |

### Admin - Lojas

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/admin/stores` | Listar lojas |
| POST | `/admin/stores` | Criar loja |
| GET | `/admin/stores/{id}` | Ver loja |
| PUT | `/admin/stores/{id}` | Atualizar loja |
| DELETE | `/admin/stores/{id}` | Desativar loja |

### Admin - Vínculos

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/admin/stores/{store}/users` | Listar usuários da loja |
| POST | `/admin/stores/{store}/users` | Vincular usuário |
| PUT | `/admin/stores/{store}/users/{user}` | Alterar role |
| DELETE | `/admin/stores/{store}/users/{user}` | Remover vínculo |

### Admin - Auditoria

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/admin/audit-logs` | Listar logs (filtros: from, to, event, store_id) |
| GET | `/admin/audit-logs/stats` | Estatísticas agregadas |
| GET | `/admin/audit-logs/{id}` | Ver log específico |

---

## 🎮 Dashboard do Vendedor (Gamificação)

O dashboard do vendedor inclui métricas motivacionais:

```json
{
  "my_sales": { "count": 5, "total": 450.00 },
  "bonus_gamification": {
    "current_amount": 450.00,
    "next_bonus_goal": 500.00,
    "gap_to_bonus": 50.00,
    "message": "Faltam R$ 50,00 para ganhar R$ 10,00 de bônus!"
  },
  "monthly_commission": {
    "sales_mtd": 8500.00,
    "current_tier": 2.0,
    "potential_commission": 450.00
  },
  "daily_pace": {
    "today_sales": 450.00,
    "average_daily_sales": 566.67,
    "status": "BEHIND"
  }
}
```

---

## 📊 Relatórios de Performance

### Store Performance

```json
{
  "store_id": 1,
  "period": "2026-01",
  "sales": {
    "current_amount": 31981.29,
    "goal_amount": 52000.00,
    "achievement_rate": 61.50
  },
  "comparison": {
    "same_period_last_year": 26950.00,
    "yoy_growth": 18.60
  },
  "forecast": {
    "linear_projection": 66100.00,
    "status": "ON_TRACK"
  }
}
```

### Cash Integrity

```json
{
  "cash_integrity": {
    "total_system_value": 150000.00,
    "total_divergence": -3750.00,
    "cash_break_percentage": 2.5,
    "status": "YELLOW"
  },
  "divergence_analysis": {
    "justified_count": 12,
    "unjustified_count": 3,
    "justified_rate": 80.00
  },
  "alerts": [
    { "type": "WARNING", "code": "ELEVATED_CASH_BREAK", "message": "Quebra de 2.50% acima do limite" }
  ]
}
```

---

## 🔄 Workflow de Fechamento

```
draft → submitted → approved
                  → rejected → draft → ...
```

- **submit**: Requer justificativa para divergências
- **approve/reject**: Somente conferente/gerente/admin
- Fechamentos aprovados são **imutáveis**

---

## 💰 Motor de Bônus

Bônus diário calculado automaticamente:

1. **Vendas do dia** (soma de `sales.amount`)
2. **Divergências**: Se existe divergência não justificada → bônus = 0
3. **Regra aplicável**: Loja-específica ou global

```json
// config_json
[
  {"min_sales": 500, "bonus": 10},
  {"min_sales": 800, "bonus": 20},
  {"min_sales": 1200, "bonus": 35}
]
```

---

## 📈 Motor de Comissão

Comissão mensal calculada por:

1. **Vendas do mês** (soma total)
2. **Meta individual** = meta da loja × split% do vendedor
3. **Atingimento** = vendas / meta × 100
4. **Taxa** = faixa por min_attainment

```json
// config_json
[
  {"min_attainment": 0, "rate": 2.0},
  {"min_attainment": 100, "rate": 3.0},
  {"min_attainment": 120, "rate": 4.0}
]
```

---

## 🔍 Sistema de Auditoria

Todas as ações críticas são registradas com contexto completo.

### Eventos Registrados

| Domínio | Eventos |
|---------|---------|
| `auth` | login, logout, logout_all, login_failed |
| `cash` | cash_closing.submit, approve, reject |
| `rules` | bonus/commission create, update, delete |
| `goals` | monthly create, update, splits.set |
| `admin` | user/store create, update, delete |

### Contexto Capturado

- `request_id` - UUID único por requisição
- `ip` - Endereço IP do cliente
- `user_agent` - Browser/client info
- `store_id` - Loja relacionada
- `actor_id` - Usuário que executou
- `before/after` - Estado antes/depois

### Request ID

Toda response inclui o mesmo `request_id` no meta e header `X-Request-Id`:

```json
{
  "data": {...},
  "meta": {
    "request_id": "abc123-uuid",
    "timestamp": "2026-01-07T12:00:00Z"
  }
}
```

---

## 🏗️ Arquitetura

```
app/
├── Actions/                    # Action classes (single responsibility)
├── Console/                    # Commands (people:sync-kpis, etc)
├── Domains/
│   ├── Finance/
│   │   └── Engines/            # BonusEngineService, CommissionEngineService
│   ├── Analytics/
│   │   ├── Clients/            # PeopleAnalyticsClient
│   │   └── Services/           # PeopleAnalyticsSyncService
│   └── Reports/
│       └── Services/           # SellerGamificationService, StorePerformanceService, CashIntegrityService
├── Enums/                      # PHP enums (CashClosingStatus, BonusStatus, etc)
├── Http/
│   ├── Controllers/Api/V1/     # Controllers finos
│   │   └── Admin/              # UserController, StoreController, AuditLogController
│   ├── Middleware/             # RequestIdMiddleware
│   ├── Requests/               # FormRequests de validação
│   ├── Resources/              # API Resources (User, Store, Sale, AuditLog)
│   └── Traits/                 # ApiResponse
├── Jobs/                       # RecalculateSellerDailyBonusJob, etc
├── Models/                     # Eloquent models
├── Observers/                  # SaleObserver, CashClosingObserver
├── Policies/                   # Authorization policies
├── Providers/                  # AppServiceProvider, AuditServiceProvider
├── Services/                   # RulesService, GoalsService, CashClosingService
└── Support/
    ├── Audit/                  # AuditContext, AuditLogger
    └── Tenancy/                # StoreContext
```

---

## ⚙️ Variáveis de Ambiente

```env
# Banco de Dados
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=maiscapinhas_erp
DB_USERNAME=root
DB_PASSWORD=

# People Analytics API (FastAPI)
PEOPLE_ANALYTICS_BASE_URL=http://localhost:8000
PEOPLE_ANALYTICS_TIMEOUT=30

# Queue
QUEUE_CONNECTION=redis  # ou sync para dev
```

---

## 🧪 Testes

```bash
# Rodar todos os testes
php artisan test

# Testes específicos
php artisan test --filter=BonusEngineTest
php artisan test --filter=CommissionEngineTest
php artisan test --filter=AuthControllerTest
```

---

## 📖 Comandos Artisan

```bash
# Sync People Analytics
php artisan people:sync-kpis --store=1 --date=2026-01-07
php artisan people:sync-kpis --all-stores

# Queue Worker
php artisan queue:work --queue=finance

# Limpar cache
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

---

## 📋 Dados do Seeder

| Entidade | Quantidade |
|----------|------------|
| Lojas | 3 (Tijucas, Itapema, Bombinhas) |
| Usuários | 10 (1 admin, 2 gerentes, 2 conferentes, 5 vendedores) |
| Vendas | ~450 (30 dias) |
| Turnos | ~84 (14 dias) |
| Regras Bônus | 2 (1 global, 1 loja) |
| Regras Comissão | 2 (1 global, 1 loja) |
| Metas Mensais | 3 (com splits) |
| People KPIs | ~126 (14 dias × 3 lojas × 3 turnos) |

---

## 🔒 Segurança

- **Autenticação**: Sanctum com Bearer tokens
- **Autorização**: RBAC via Policies (admin, gerente, conferente, vendedor)
- **Store Scope**: Usuário só vê dados das lojas onde tem acesso
- **Auditoria**: Todas as ações críticas são logadas
- **Sanitização**: Dados sensíveis nunca são logados (passwords, tokens)
- **CORS**: Configurado para origens permitidas

---

## 📄 Licença

Proprietary - MaisCapinhas © 2026
