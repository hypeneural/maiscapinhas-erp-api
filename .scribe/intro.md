# Introduction

API REST para gestão de vendas, metas, fechamento de caixa, bônus e comissões do ERP MaisCapinhas.

<aside>
    <strong>Base URL</strong>: <code>http://localhost:8000</code>
</aside>

    Bem-vindo à documentação da **MaisCapinhas ERP API**! 🎉

    Esta API permite gerenciar todo o fluxo operacional das lojas de capinhas, incluindo:
    - **Autenticação** via Bearer Token (desenvolvimento) ou Cookie SPA (produção com frontend React)
    - **Vendas** e metas por loja/vendedor
    - **Fechamento de caixa** com workflow de aprovação
    - **Bônus diário** e **comissão mensal** baseados em regras configuráveis
    - **People Analytics** para KPIs de fluxo de pessoas

    ---

    ## 🔐 Autenticação

    ### Modo Bearer Token (recomendado para desenvolvimento/Postman)
    ```bash
    curl -X POST /api/v1/auth/login \
      -H "Content-Type: application/json" \
      -d '{"email":"admin@maiscapinhas.com.br", "password":"password"}'
    ```
    Use o token retornado no header: `Authorization: Bearer {token}`

    ### Modo Cookie SPA (para frontend React/Vue)
    ```javascript
    // 1. Obter CSRF token
    await fetch('/sanctum/csrf-cookie', { credentials: 'include' });

    // 2. Login
    await fetch('/api/v1/auth/login', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: '...', password: '...' })
    });
    ```

    ---

    ## 📦 Formato de Resposta

    **Sucesso:**
    ```json
    {
        "data": { ... },
        "meta": { "timestamp": "2026-01-07T12:00:00Z" }
    }
    ```

    **Erro:**
    ```json
    {
        "error": {
            "code": 422,
            "message": "Validation failed",
            "errors": { "email": ["O campo email é obrigatório."] }
        }
    }
    ```

    ---

    <aside>À medida que você rola, você verá exemplos de código para trabalhar com a API em diferentes linguagens na área escura à direita.</aside>

