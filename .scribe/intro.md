# Introduction

API REST para gestão de vendas, metas, fechamento de caixa, bônus e comissões do ERP MaisCapinhas.

<aside>
    <strong>Base URL</strong>: <code>https://api.maiscapinhas.com.br</code>
</aside>

    Bem-vindo à documentação da **MaisCapinhas ERP API**! 🎉

    Esta API foi desenvolvida por **Anderson M. Vieira** ([Hype Neural](https://hypeneural.com.br)) para a rede de lojas **Mais Capinhas**.

    ---

    ## 📞 Suporte

    Dúvidas ou problemas? Entre em contato:

    - **WhatsApp:** [(48) 99655-3954](https://wa.me/5548996553954)
    - **Desenvolvedor:** Anderson M. Vieira (Hype Neural)

    ---

    ## 🚀 Recursos da API

    Esta API permite gerenciar todo o fluxo operacional das lojas de capinhas, incluindo:

    - **Autenticação** via Bearer Token ou Cookie SPA
    - **Clientes** e dispositivos
    - **Capas personalizadas** com upload de fotos
    - **Pedidos** e vendas por loja/vendedor
    - **Fluxo de produção** (carrinho → pedido → fábrica)
    - **Fechamento de caixa** com workflow de aprovação
    - **Bônus diário** e **comissão mensal** baseados em regras configuráveis
    - **Avisos internos** para comunicação com a equipe
    - **Catálogo** de marcas e modelos de telefone

    ---

    ## 🔐 Autenticação

    ### Modo Bearer Token (recomendado para integração)
    ```bash
    curl -X POST https://api.maiscapinhas.com.br/api/v1/auth/login \
      -H "Content-Type: application/json" \
      -d '{"email":"seu@email.com", "password":"sua_senha"}'
    ```
    Use o token retornado no header: `Authorization: Bearer {token}`

    ### Modo Cookie SPA (para frontend React/Vue)
    ```javascript
    // 1. Obter CSRF token
    await fetch('https://api.maiscapinhas.com.br/sanctum/csrf-cookie', { credentials: 'include' });

    // 2. Login
    await fetch('https://api.maiscapinhas.com.br/api/v1/auth/login', {
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
        "message": "Operação realizada com sucesso."
    }
    ```

    **Erro:**
    ```json
    {
        "message": "Validation failed",
        "errors": { "email": ["O campo email é obrigatório."] }
    }
    ```

    ---

    <aside>À medida que você rola, você verá exemplos de código para trabalhar com a API em diferentes linguagens na área escura à direita.</aside>

