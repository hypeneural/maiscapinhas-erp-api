# MaisCapinhas ERP API

Sistema ERP para gestão de vendas, metas, bônus e comissões de lojas de capinhas.

## Quick Start

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

## 📚 Documentação da API

A API possui documentação interativa gerada automaticamente via [Scribe](https://scribe.knuckles.wtf/).

### Gerar/Atualizar Documentação

```bash
php artisan scribe:generate
```

### Onde ver

| Arquivo | Descrição |
|---------|-----------|
| `public/docs/index.html` | Documentação HTML interativa |
| `public/docs/collection.json` | Postman Collection v2.1 |
| `public/docs/openapi.yaml` | OpenAPI 3.0 Spec (Swagger) |

**Para visualizar localmente:**
- Via Laragon: Acesse `http://maiscapinhas-erp-api.test/docs/index.html`
- Via artisan serve: Acesse `http://localhost:8000/docs/index.html`

### Importar no Postman

1. Abra o Postman
2. **Import** → **File** → Selecione `public/docs/collection.json`
3. Configure a variável `baseUrl` para `http://localhost:8000/api/v1`
4. Pronto! Todos os endpoints estarão disponíveis

### Como documentar novos endpoints

Ao criar novos endpoints, adicione docblocks nos controllers:

```php
/**
 * @group Nome do Grupo
 */
class MeuController extends Controller
{
    /**
     * Título curto do endpoint
     *
     * Descrição detalhada do que o endpoint faz,
     * quem pode usar, e regras de negócio.
     *
     * @queryParam param1 string Descrição do parâmetro. Example: valor
     * @bodyParam campo string required Campo obrigatório. Example: valor
     *
     * @response 200 scenario="Sucesso" {"data": {...}}
     * @response 422 scenario="Erro" {"error": {...}}
     */
    public function metodo() {}
}
```

**Dicas:**
- Use `@group` para organizar endpoints
- Use `@unauthenticated` para endpoints públicos
- Use `@queryParam`, `@bodyParam`, `@urlParam` com exemplos
- Use `@response` para documentar cenários de resposta



## Autenticação

A API usa **Laravel Sanctum** com Bearer Token.

```http
POST /api/v1/auth/login
Content-Type: application/json

{"email": "admin@maiscapinhas.com.br", "password": "password"}
```

Use o token retornado no header `Authorization: Bearer {token}`.

## Usuários de Teste

| Email | Role | Lojas |
|-------|------|-------|
| admin@maiscapinhas.com.br | Admin | Todas |
| carlos.gerente@maiscapinhas.com.br | Gerente | Tijucas, Itapema |
| maria.gerente@maiscapinhas.com.br | Gerente | Bombinhas |
| ana.conferente@maiscapinhas.com.br | Conferente | Tijucas, Itapema |
| joao.vendedor@maiscapinhas.com.br | Vendedor | Tijucas |

Senha padrão: `password`

## Endpoints da API

### Públicos

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/api/v1/health` | Health check |
| GET | `/api/v1/version` | Versão da API |
| POST | `/api/v1/auth/login` | Login |

### Autenticados

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| POST | `/auth/logout` | Logout |
| GET | `/me` | Perfil + lojas |
| GET | `/stores` | Listar lojas |
| GET | `/sales` | Listar vendas |

### Caixa

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/cash/shifts` | Listar turnos |
| POST | `/cash/shifts` | Criar turno |
| GET | `/cash/closings/{shift}` | Ver fechamento |
| POST | `/cash/closings/{shift}/submit` | Enviar fechamento |
| POST | `/cash/closings/{shift}/approve` | Aprovar (conferente+) |
| POST | `/cash/closings/{shift}/reject` | Rejeitar + motivo |

### Regras (admin/gerente)

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET/POST | `/rules/bonus` | CRUD regras bônus |
| GET/PUT/DELETE | `/rules/bonus/{id}` | Regra específica |
| GET/POST | `/rules/commission` | CRUD regras comissão |
| GET/PUT/DELETE | `/rules/commission/{id}` | Regra específica |

### Metas (admin/gerente)

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET/POST | `/goals/monthly` | CRUD metas mensais |
| GET/PUT/DELETE | `/goals/monthly/{id}` | Meta específica |
| PUT | `/goals/monthly/{id}/splits` | Definir splits (soma=100%) |

### Extrato Financeiro

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/finance/bonus` | Ledger bônus diário |
| GET | `/finance/commission` | Ledger comissão mensal |

### People Analytics

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/analytics/people/shift` | KPIs por turno |
| POST | `/analytics/people/shift` | Inserir KPI manual |

### Dashboards

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/dashboard/vendedor` | Dashboard vendedor |
| GET | `/dashboard/conferente` | Dashboard conferente |
| GET | `/dashboard/admin` | Dashboard admin |

## Formato de Resposta

### Sucesso
```json
{"data": {...}, "meta": {"timestamp": "..."}}
```

### Erro
```json
{"error": {"code": 422, "message": "...", "errors": {...}}}
```

## Workflow de Fechamento

```
draft → submitted → approved
                  → rejected → draft → ...
```

- **submit**: Requer justificativa para divergências
- **approve/reject**: Somente conferente/gerente/admin

## Motor de Bônus

O bônus diário é calculado automaticamente baseado em:

1. **Vendas do dia** (soma de `sales.amount`)
2. **Divergências do caixa**: Se existe divergência não justificada → bônus = 0
3. **Regra aplicável**: Loja-específica ou global, faixa por min_sales

```json
// Exemplo config_json
[
  {"min_sales": 500, "bonus": 10},
  {"min_sales": 800, "bonus": 20}
]
```

## Motor de Comissão

Comissão mensal calculada por:

1. **Vendas do mês** (soma total)
2. **Meta individual** = meta da loja × split% do vendedor
3. **Atingimento** = vendas / meta × 100
4. **Taxa** = faixa por min_attainment em percentual 

```json
// Exemplo config_json
[
  {"min_attainment": 0, "rate": 2},
  {"min_attainment": 100, "rate": 3},
  {"min_attainment": 120, "rate": 4}
]
```

## Jobs Assíncronos

Bonus e comissão são recalculados automaticamente quando:
- Venda criada/atualizada
- Fechamento submit/approve/reject

```bash
# Rodar queue worker
php artisan queue:work --queue=finance
```

## Comandos Artisan

```bash
# Sync People Analytics
php artisan people:sync-kpis --store=1 --date=2026-01-07
php artisan people:sync-kpis --all-stores

# Recalcular (via jobs)
php artisan queue:work --queue=finance
```

## Variáveis de Ambiente

```env
# People Analytics API (FastAPI)
PEOPLE_ANALYTICS_BASE_URL=http://localhost:8000
PEOPLE_ANALYTICS_TIMEOUT=30

# Queue
QUEUE_CONNECTION=redis
# ou sync para desenvolvimento
QUEUE_CONNECTION=sync
```

## Testes

```bash
# Rodar todos os testes
php artisan test

# Testes específicos
php artisan test --filter=BonusEngineTest
php artisan test --filter=CommissionEngineTest
php artisan test --filter=PeopleAnalyticsTest
```

## Arquitetura

```
app/
├── Domains/
│   ├── Finance/
│   │   └── Engines/        # BonusEngineService, CommissionEngineService
│   └── Analytics/
│       ├── Clients/        # PeopleAnalyticsClient
│       └── Services/       # PeopleAnalyticsSyncService
├── Enums/                  # PHP enums (CashClosingStatus, BonusStatus, etc)
├── Http/Controllers/Api/V1/
├── Jobs/                   # RecalculateSellerDailyBonusJob, etc
├── Models/
├── Observers/              # SaleObserver, CashClosingObserver
├── Policies/
├── Services/               # RulesService, GoalsService
└── Support/Tenancy/        # StoreContext
```

## Dados do Seeder

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
