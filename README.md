# Mais Capinhas ERP API

O **Mais Capinhas ERP** é o sistema nervoso central das operações de varejo da rede. Esta API RESTful gerencia desde a autenticação de vendedores até o fechamento complexo de caixa, passando por metas, comissões e dashboards em tempo real.

> **Desenvolvido por Anderson Marques (Hype Neural)** 
> Empresa de tecnologia sediada em Tijucas/SC, focada em soluções neurais e de alta performance.

---

## 🚀 1. Stack Tecnológica & Arquitetura

O projeto segue uma arquitetura **Service-Oriented** sobre o **Laravel 12**, garantindo que a regra de negócio não "vaze" para os controladores.

| Camada | Tecnologia | Detalhes |
|:---|:---|:---|
| **Linguagem** | PHP 8.2+ | Strict Types (`declare(strict_types=1)`) em tudo. |
| **Framework** | Laravel 12 | Utilizando as últimas features do ecossistema. |
| **Auth** | Sanctum | Tokens do tipo Bearer para autenticação Stateless. |
| **Banco de Dados** | MySQL 8.0 | InnoDB, UTF8mb4. |
| **Testes** | Pest PHP | Suíte de testes automatizados com sintaxe expressiva. |
| **Docs** | Markdown | Documentação viva no repositório. |

### Bibliotecas Principais
- `spatie/laravel-permission`: Controle de acesso baseado em função (RBAC).
- `spatie/laravel-activitylog`: Rastreamento de ações críticas (quem fez o quê).
- `laravel/pint`: Padronização de código (PSR-12).

---

## 🔐 2. Autenticação e Segurança

### Fluxo de Login
1. O cliente envia `email`, `password` e `device_name`.
2. O sistema valida as credenciais (hash `bcrypt`).
3. Se válido, retorna um **Token Sanctum** em texto plano.
4. O cliente deve enviar este token no header `Authorization: Bearer <token>` em todas as requisições subsequentes.

### Permissões (RBAC)
O sistema possui 3 papéis principais:
- **`admin`**: Acesso irrestrito a todas as lojas e configurações.
- **`gerente` (Manager)**: Pode aprovar fechamentos e ver dados da sua loja.
- **`vendedor` (Salesperson)**: Vende, abre turno e submete fechamentos.

### Escopo de Loja (Tenant Isolation)
A segurança de dados é garantida por `GlobalScopes` e `Policies`. Um vendedor da **Loja A** *nunca* conseguirá ver dados da **Loja B**, mesmo se tentar manipular IDs na URL.

---

## 🔌 3. API Reference

### 3.1. Auth

#### Login
Rettorna o token de acesso e dados básicos do usuário.

- **POST** `/api/v1/auth/login`

**Request Body:**
```json
{
  "email": "vendedor@maiscapinhas.com.br",
  "password": "senha-secreta",
  "device_name": "Tablet Loja 01"
}
```

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "token": "10|abc123xyz...",
    "token_type": "Bearer",
    "user": {
      "id": 15,
      "name": "João Silva",
      "email": "vendedor@maiscapinhas.com.br"
    }
  },
  "message": "Login successful"
}
```

---

### 3.2. Dashboards
Endpoints otimizados que retornam **DTOs agregados** para montar telas inteiras com uma única requisição.

#### Dashboard do Vendedor
Resumo operacional do dia para o vendedor.

- **GET** `/api/v1/dashboard/vendedor?store_id=1`

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "date": "2026-01-07",
    "my_sales": {
      "count": 12,
      "total": 1450.50
    },
    "store_sales": {
      "count": 45,
      "total": 5200.00
    },
    "my_shifts": [
      {
        "id": 102,
        "date": "2026-01-07",
        "status": "open",
        "shift_code": "T1"
      }
    ]
  }
}
```

#### Dashboard do Conferente
Focado em pendências que exigem atenção (fechamentos aguardando aprovação).

- **GET** `/api/v1/dashboard/conferente?store_id=1`

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "pending_count": 2,
    "pending_closings": [
      {
        "id": 505,
        "status": "submitted",
        "cash_shift": {
            "seller": { "name": "Maria Cunha" }
        }
      }
    ]
  }
}
```

---

### 3.3. Gestão de Caixa (Cash Management)
O núcleo financeiro do ERP.

#### Submeter Fechamento
O vendedor envia os valores contados. O backend calcula a diferença automaticamente.

- **POST** `/api/v1/cash/closings/{id}/submit`

**Lógica:**
1. Verifica se o status é `draft` ou `rejected`.
2. Recalcula as diferenças (`real_value - system_value`).
3. Bloqueia se houver diferenças **sem justificativa**.
4. Muda status para `submitted`.

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "id": 505,
    "status": "submitted",
    "lines": [
      {
        "label": "Dinheiro",
        "system_value": 500.00,
        "real_value": 450.00,
        "diff_value": -50.00,
        "justification": "Sangria não lançada"
      }
    ]
  }
}
```

#### Aprovar Fechamento (Admin/Gerente)
Finaliza o ciclo do dinheiro.

- **POST** `/api/v1/cash/closings/{id}/approve`

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Closing approved successfully."
}
```

---

## 🏛️ 4. Estrutura do Banco de Dados

### Diagrama ER (Simplificado)

- **`stores`**: Unidades de negócio.
- **`users`**: Colaboradores.
- **`store_users`** (N:N): Quais lojas um usuário acessa.
- **`sales`**: Vendas importadas do sistema PDV.
  - `store_id` (FK), `seller_id` (FK), `amount`, `payment_method`.
- **`cash_shifts`**: Turno de trabalho.
  - `seller_id`, `start_time`, `end_time`, `status` (open/closed).
- **`cash_closings`**: O documento de fechamento.
  - `cash_shift_id`, `status` (draft/submitted/approved/rejected), `version`.
- **`cash_closing_lines`**: Detalhe financeiro.
  - `type` (money/card), `system_value`, `real_value`.

---

## �️ 5. Guia de Desenvolvimento

### Instalação Local

```bash
# 1. Clone
git clone ...

# 2. Deps
composer install

# 3. Env
cp .env.example .env
# Configure DB credentials no .env

# 4. Setup
php artisan key:generate
php artisan migrate --seed

# 5. Run
php artisan serve
```

### Rodando Testes
Use o Pest para garantir que nada quebrou.

```bash
# Todos os testes
php artisan test

# Apenas testes de Caixa
php artisan test --filter=Cash
```

### Como Adicionar uma Nova Feature

1.  **Crie a Rota**: `routes/api_v1.php`.
2.  **Controller**: `app/Http/Controllers/Api/V1`.
3.  **Service**: Se tiver lógica de negócio, crie um Service em `app/Services`.
4.  **Teste**: Crie um teste em `tests/Feature/SuaFeatureTest.php`.

---

## 🔮 6. Roadmap & Manutenção

- [ ] **Webhooks**: Notificar Slack/WhatsApp quando houver divergência de caixa > R$ 100,00.
- [ ] **Relatórios PDF**: Gerar comprovante de fechamento em PDF.
- [ ] **Cache Redis**: Otimizar dashboards de Admin para grandes volumes de dados.
- [ ] **Auditoria Geográfica**: Salvar Lat/Long do device no momento do login.

---

**© 2026 Mais Capinhas ERP**. Desenvolvido com ❤️ e PHP.
