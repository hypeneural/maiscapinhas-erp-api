# 🏪 MaisCapinhas ERP API

> Sistema ERP completo para gestão de vendas, metas, bônus, comissões e auditoria de lojas MaisCapinhas.

[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?logo=mysql)](https://mysql.com)

---

## 📋 Índice

- [Funcionalidades](#-funcionalidades)
- [Stack Tecnológica](#-stack-tecnológica)
- [Quick Start](#-quick-start)
- [Autenticação](#-autenticação)
- [Endpoints da API](#-endpoints-da-api)
- [Dashboards & Gamificação](#-dashboards--gamificação)
- [Relatórios](#-relatórios)
- [Motor de Bônus](#-motor-de-bônus)
- [Motor de Comissão](#-motor-de-comissão)
- [Sistema de Auditoria](#-sistema-de-auditoria)
- [Arquitetura](#-arquitetura)
- [Configuração](#-configuração)

---

## 🚀 Funcionalidades

| Módulo | Descrição | Status |
|--------|-----------|--------|
| **Autenticação** | Login/Logout com Sanctum, recuperação de senha, multi-dispositivo | ✅ |
| **Upload de Mídia** | Avatar de usuário e foto de fachada da loja | ✅ |
| **Multi-Loja** | RBAC por loja (admin, gerente, conferente, vendedor) | ✅ |
| **Vendas** | CRUD completo, importação, filtros avançados | ✅ |
| **Fechamento de Caixa** | Workflow draft→submitted→approved, divergências pendentes | ✅ |
| **Bônus Diário** | Motor automático com faixas, simulador, extrato por vendedor | ✅ |
| **Comissão Mensal** | Motor por atingimento, projeção com cenários | ✅ |
| **Metas & Splits** | Metas por loja com distribuição percentual | ✅ |
| **Ranking** | Ranking de vendedores com pódio e estatísticas | ✅ |
| **Dashboards** | KPIs personalizados por role | ✅ |
| **Relatórios** | Performance, integridade de caixa, aniversariantes | ✅ |
| **Auditoria** | Logs completos com request_id, IP, user-agent | ✅ |
| **People Analytics** | Integração FastAPI para KPIs de turno | ✅ |

---

## 📋 Stack Tecnológica

### Core

| Tecnologia | Versão | Propósito |
|------------|--------|-----------|
| **Laravel** | 12 | Framework PHP |
| **PHP** | 8.2+ | Linguagem |
| **MySQL/MariaDB** | 8.0/10.6 | Banco de Dados |
| **Laravel Sanctum** | Latest | Autenticação Bearer + SPA |

### Bibliotecas

| Biblioteca | Propósito |
|------------|-----------|
| **Spatie ActivityLog** | Auditoria de modelos |
| **Scribe** | Documentação OpenAPI |
| **Pest PHP** | Testes automatizados |
| **Guzzle** | Cliente HTTP (People Analytics) |

### Infraestrutura

| Componente | Opções |
|------------|--------|
| **Queue** | Redis / Sync |
| **Cache** | File / Redis |
| **Storage** | Local / S3 |

---

## 🏁 Quick Start

```bash
# 1. Instalar dependências
composer install

# 2. Copiar .env e configurar
cp .env.example .env
php artisan key:generate

# 3. Configurar banco de dados no .env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=maiscapinhas_erp
DB_USERNAME=root
DB_PASSWORD=

# 4. Rodar migrations + seeders
php artisan migrate:fresh --seed

# 5. Gerar link storage (para uploads)
php artisan storage:link

# 6. Iniciar servidor
php artisan serve
```

### Geração de Token (para testes)

```bash
php generate_token.php
# Output: Token Bearer para usar no Postman/cURL
```

---

## 🔐 Autenticação

### Login

```http
POST /api/v1/auth/login
Content-Type: application/json

{
  "email": "admin@maiscapinhas.com.br",
  "password": "password",
  "device_name": "postman"
}
```

**Response:**
```json
{
  "data": {
    "token": "1|abc123xyz...",
    "token_type": "Bearer",
    "user": {
      "id": 1,
      "name": "Admin Sistema",
      "email": "admin@maiscapinhas.com.br",
      "stores": [
        { "id": 1, "name": "Tijucas", "role": "admin" }
      ]
    }
  },
  "meta": { "request_id": "uuid", "timestamp": "2026-01-07T12:00:00Z" }
}
```

### Usuários de Teste

| Email | Senha | Role | Lojas |
|-------|-------|------|-------|
| admin@maiscapinhas.com.br | password | Admin | Todas |
| carlos.gerente@maiscapinhas.com.br | password | Gerente | Tijucas, Itapema |
| ana.conferente@maiscapinhas.com.br | password | Conferente | Tijucas |
| joao.vendedor@maiscapinhas.com.br | password | Vendedor | Tijucas |

---

## 📡 Endpoints da API

### Públicos (Sem Autenticação)

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/health` | Health check |
| GET | `/version` | Versão da API |
| POST | `/auth/login` | Login e obter token |
| POST | `/auth/forgot-password` | Solicitar recuperação de senha |
| POST | `/auth/reset-password` | Redefinir senha com token |

### Autenticação

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| POST | `/auth/logout` | Logout (revogar token) |
| POST | `/auth/logout-all` | Revogar todos os tokens |
| PUT | `/auth/password` | Alterar própria senha |
| GET | `/me` | Perfil + lojas do usuário |

### Upload de Mídia

| Método | Endpoint | Descrição | Validações |
|--------|----------|-----------|------------|
| PUT | `/users/{user}/avatar` | Atualizar avatar | jpg/png/webp, max 2MB, min 200x200 |
| PUT | `/stores/{store}/photo` | Atualizar foto loja | jpg/png/webp, max 5MB, min 800x600 |

### Lojas

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/stores` | Listar lojas do usuário |
| GET | `/stores/{id}` | Detalhes (inclui codigo, troco_padrao) |
| GET | `/stores/{id}/sellers` | Vendedores da loja com stats MTD |

### Vendas (CRUD)

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/sales` | Listar (filtros: store_id, seller_id, from, to) |
| POST | `/sales` | Criar venda |
| GET | `/sales/{id}` | Detalhes |
| PUT | `/sales/{id}` | Atualizar (gerente+) |
| DELETE | `/sales/{id}` | Excluir (admin) |

### Fechamento de Caixa

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/cash/shifts` | Listar turnos |
| POST | `/cash/shifts` | Criar turno |
| GET | `/cash/shifts/{id}` | Ver turno |
| GET | `/cash/shifts/pending` | **Turnos pendentes** (conferente+) |
| GET | `/cash/shifts/divergent` | **Turnos com divergência** |
| GET | `/cash/closings/{shift}` | Ver fechamento |
| POST | `/cash/closings/{shift}/submit` | Submeter fechamento |
| POST | `/cash/closings/{shift}/approve` | Aprovar |
| POST | `/cash/closings/{shift}/reject` | Rejeitar + motivo |

### Finanças - Bônus

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/finance/bonus` | Ledger de bônus |
| GET | `/finance/bonus/seller/{id}` | **Extrato do vendedor** |
| GET | `/finance/bonus/calculate` | **Simulador de bônus** |

### Finanças - Comissão

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/finance/commission` | Ledger de comissão |
| GET | `/finance/commission/seller/{id}` | **Comissão do vendedor** |
| GET | `/finance/commission/projection/{id}` | **Projeção com cenários** |

### Regras (Admin/Gerente)

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET/POST | `/rules/bonus` | CRUD regras bônus |
| GET/PUT/DELETE | `/rules/bonus/{id}` | Gerenciar regra |
| GET/POST | `/rules/commission` | CRUD regras comissão |
| GET/PUT/DELETE | `/rules/commission/{id}` | Gerenciar regra |

### Metas

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET/POST | `/goals/monthly` | CRUD metas mensais |
| GET/PUT | `/goals/monthly/{id}` | Gerenciar meta |
| PUT | `/goals/monthly/{id}/splits` | Definir splits (soma=100%) |

### Dashboards

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/dashboard/seller` | Dashboard vendedor (gamificação) |
| GET | `/dashboard/store` | Dashboard loja |
| GET | `/dashboard/admin` | Dashboard consolidado |

### Relatórios

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/reports/store-performance` | Performance da loja (YoY) |
| GET | `/reports/consolidated` | Performance multi-loja |
| GET | `/reports/cash-integrity` | Integridade de caixa |
| GET | `/reports/ranking` | **Ranking de vendedores** |

### Usuários

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/users/birthdays` | **Aniversariantes do mês** |

### Admin - Usuários

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET/POST | `/admin/users` | CRUD usuários |
| GET/PUT/DELETE | `/admin/users/{id}` | Gerenciar usuário |

### Admin - Lojas

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET/POST | `/admin/stores` | CRUD lojas |
| GET/PUT/DELETE | `/admin/stores/{id}` | Gerenciar loja |
| GET | `/admin/stores/{store}/users` | Usuários da loja |
| POST | `/admin/stores/{store}/users` | Vincular usuário |
| PUT/DELETE | `/admin/stores/{store}/users/{user}` | Alterar/remover vínculo |

### Admin - Auditoria

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/admin/audit-logs` | Listar logs (filtros avançados) |
| GET | `/admin/audit-logs/stats` | Estatísticas agregadas |
| GET | `/admin/audit-logs/{id}` | Ver log específico |

### People Analytics

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/analytics/people/shift` | KPIs por turno |
| POST | `/analytics/people/shift` | Inserir KPI manual |

---

## 🎮 Dashboards & Gamificação

### Dashboard do Vendedor

```json
{
  "today": {
    "total_sold": 850.00,
    "daily_goal": 1200.00,
    "achievement_rate": 70.83,
    "current_bonus": 20.00,
    "next_bonus_tier": { "min_sales": 1000, "bonus": 35 }
  },
  "month": {
    "total_sold": 18500.00,
    "goal": 25000.00,
    "achievement_rate": 74.0,
    "projected_commission": 555.00
  },
  "gamification": {
    "gap_to_next_bonus": 150.00,
    "message": "Faltam R$ 150 para ganhar R$ 35 de bônus!"
  },
  "shift": {
    "started_at": "08:00",
    "end_time": "18:00",
    "minutes_remaining": 180
  }
}
```

---

## 📊 Relatórios

### Ranking de Vendedores

```json
{
  "period": "2026-01",
  "podium": [
    {
      "position": 1,
      "seller": { "id": 5, "name": "João Silva", "avatar_url": "...", "store_name": "Tijucas" },
      "total_sold": 85000.00,
      "goal": 75000.00,
      "achievement_rate": 113.33,
      "bonus_accumulated": 450.00
    }
  ],
  "ranking": [...],
  "stats": {
    "total_sellers": 25,
    "above_goal": 12,
    "average_achievement": 92.5
  }
}
```

### Projeção de Comissão

```json
{
  "seller": { "id": 5, "name": "João Silva" },
  "current": {
    "sales_mtd": 18500.00,
    "goal": 25000.00,
    "achievement_rate": 74.0,
    "current_tier": 2.0
  },
  "projection": {
    "optimistic": { "sales": 28500.00, "rate": 3.0, "commission": 855.00 },
    "realistic": { "sales": 24000.00, "rate": 2.0, "commission": 480.00 },
    "pessimistic": { "sales": 20000.00, "rate": 2.0, "commission": 400.00 }
  },
  "days_remaining": 8
}
```

---

## 💰 Motor de Bônus

Bônus diário calculado automaticamente por faixas:

```json
// Regra exemplo (config_json)
[
  { "min_sales": 500, "bonus": 10 },
  { "min_sales": 800, "bonus": 20 },
  { "min_sales": 1200, "bonus": 35 },
  { "min_sales": 1800, "bonus": 50 }
]
```

**Critérios:**
1. Vendas do dia (soma de `sales.amount`)
2. Se há divergência não justificada → bônus = 0
3. Regra loja-específica tem prioridade sobre global

---

## 📈 Motor de Comissão

Comissão mensal escalonada por atingimento de meta:

```json
// Regra exemplo (config_json)
[
  { "min_attainment": 0, "rate": 2.0 },
  { "min_attainment": 100, "rate": 3.0 },
  { "min_attainment": 120, "rate": 4.0 },
  { "min_attainment": 150, "rate": 5.0 }
]
```

**Cálculo:**
1. Meta individual = Meta loja × Split% do vendedor
2. Atingimento = Vendas / Meta × 100
3. Comissão = Vendas × Taxa aplicável

---

## 🔍 Sistema de Auditoria

### Eventos Registrados

| Domínio | Eventos |
|---------|---------|
| `auth` | login, logout, login_failed, password_reset |
| `cash` | submit, approve, reject |
| `rules` | create, update, delete |
| `goals` | create, update, splits.set |
| `user` | avatar_updated, avatar_removed |
| `admin` | user/store CRUD |

### Contexto Capturado

```json
{
  "id": 150,
  "event": "auth.login",
  "log_name": "auth",
  "context": {
    "request_id": "abc123-uuid",
    "ip": "192.168.1.100",
    "user_agent": "Mozilla/5.0..."
  },
  "causer": { "id": 1, "name": "Admin" },
  "store_id": 1
}
```

---

## 🏗️ Arquitetura

```
app/
├── Domains/
│   ├── Finance/
│   │   └── Engines/           # BonusEngine, CommissionEngine
│   ├── Reports/
│   │   └── Services/          # RankingService, PerformanceService
│   └── Analytics/
│       └── Clients/           # PeopleAnalyticsClient
├── Http/
│   ├── Controllers/Api/V1/
│   │   ├── AuthController
│   │   ├── PasswordResetController  # NEW
│   │   ├── AvatarController         # NEW
│   │   ├── RankingController        # NEW
│   │   ├── FinanceController        # UPDATED
│   │   ├── CashShiftController      # UPDATED (pending/divergent)
│   │   └── Admin/
│   ├── Middleware/
│   │   └── RequestIdMiddleware
│   └── Resources/
├── Models/
│   ├── User (avatar_url, extended fields)
│   ├── Store (codigo, troco_padrao, photo_url)
│   └── StoreGoalSplit (storeMonthlyGoal relation)
└── Support/
    └── Audit/                 # AuditContext, AuditLogger
```

---

## ⚙️ Configuração

### Variáveis de Ambiente (Produção)

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.maiscapinhas.com.br

# Banco de Dados
DB_CONNECTION=mariadb
DB_HOST=186.209.113.134
DB_DATABASE=erp_maiscapinhas
DB_USERNAME=erp_maiscapinhas
DB_PASSWORD=***

# Session
SESSION_DOMAIN=.maiscapinhas.com.br
SESSION_ENCRYPT=true

# CORS
SANCTUM_STATEFUL_DOMAINS=app.maiscapinhas.com.br

# Log
LOG_CHANNEL=daily
LOG_LEVEL=error
```

### Comandos Úteis

```bash
# Produção - Otimizar
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize

# Gerar Documentação
php artisan scribe:generate

# Sync People Analytics
php artisan people:sync-kpis --all-stores

# Queue Worker
php artisan queue:work --queue=finance
```

---

## 📋 Dados do Seeder

| Entidade | Quantidade |
|----------|------------|
| Lojas | 3 (Tijucas, Itapema, Bombinhas) |
| Usuários | 10 |
| Vendas | ~495 |
| Turnos | ~84 |
| Regras Bônus | 2 |
| Regras Comissão | 2 |
| Metas Mensais | 3 (com splits) |

---

## 🔒 Segurança

- **Autenticação**: Sanctum Bearer tokens
- **Autorização**: RBAC via Policies
- **Store Scope**: Dados isolados por loja
- **Auditoria**: Todas as ações críticas logadas
- **Sanitização**: Passwords/tokens nunca logados
- **CORS**: Origens configuráveis
- **Rate Limiting**: Recuperação de senha (3/min)

---

## 📖 Documentação

| Recurso | URL |
|---------|-----|
| Docs Interativa | `https://api.maiscapinhas.com.br/docs` |
| OpenAPI Spec | `https://api.maiscapinhas.com.br/docs/openapi.yaml` |
| Postman Collection | `https://api.maiscapinhas.com.br/docs/collection.json` |

---

## 📄 Licença

Proprietary - MaisCapinhas © 2026
