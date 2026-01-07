<?php

use Knuckles\Scribe\Extracting\Strategies;
use Knuckles\Scribe\Config\Defaults;
use Knuckles\Scribe\Config\AuthIn;
use function Knuckles\Scribe\Config\{removeStrategies, configureStrategy};

return [
    // Título da documentação
    'title' => 'MaisCapinhas ERP API - Documentação',

    // Descrição curta
    'description' => 'API REST para gestão de vendas, metas, fechamento de caixa, bônus e comissões do ERP MaisCapinhas.',

    // Texto de introdução em pt-BR
    'intro_text' => <<<INTRO
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
        curl -X POST /api/v1/auth/login \\
          -H "Content-Type: application/json" \\
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
    INTRO,

    // URL base
    'base_url' => env('APP_URL', 'http://localhost:8000'),

    // Rotas a documentar
    'routes' => [
        [
            'match' => [
                'prefixes' => ['api/v1/*'],
                'domains' => ['*'],
            ],
            'include' => [],
            'exclude' => [
                // Excluir rotas internas do Sanctum
                'sanctum/*',
            ],
        ],
    ],

    // Tipo de saída: static para gerar HTML + collection.json + openapi.yaml
    'type' => 'static',

    'theme' => 'default',

    'static' => [
        'output_path' => 'public/docs',
    ],

    'laravel' => [
        'add_routes' => true,
        'docs_url' => '/docs',
        'assets_directory' => null,
        'middleware' => [],
    ],

    'external' => [
        'html_attributes' => []
    ],

    'try_it_out' => [
        'enabled' => true,
        'base_url' => null,
        // Para modo SPA com cookies, habilite CSRF
        'use_csrf' => false,
        'csrf_url' => '/sanctum/csrf-cookie',
    ],

    // Configuração de autenticação
    'auth' => [
        'enabled' => true,
        'default' => true, // Todos endpoints autenticados por padrão
        'in' => AuthIn::BEARER->value,
        'name' => 'Authorization',
        'use_value' => env('SCRIBE_AUTH_KEY'),
        'placeholder' => '{SEU_TOKEN}',
        'extra_info' => <<<AUTH
            Para obter um token, faça login em `POST /api/v1/auth/login` com email e senha.

            **Usuários de teste:**
            - `admin@maiscapinhas.com.br` / `password` (Admin - todas as lojas)
            - `joao.vendedor@maiscapinhas.com.br` / `password` (Vendedor - Tijucas)

            O token deve ser enviado no header `Authorization: Bearer {token}`.

            **Modo SPA (Cookie):** Para aplicações frontend, use `withCredentials: true` após obter o CSRF token em `/sanctum/csrf-cookie`.
        AUTH,
    ],

    // Linguagens de exemplo
    'example_languages' => [
        'bash',
        'javascript',
    ],

    // Postman Collection
    'postman' => [
        'enabled' => true,
        'overrides' => [
            'info.version' => '1.0.0',
        ],
    ],

    // OpenAPI Spec
    'openapi' => [
        'enabled' => true,
        'version' => '3.0.3',
        'overrides' => [
            'info.version' => '1.0.0',
        ],
        'generators' => [],
    ],

    // Ordenação de grupos
    'groups' => [
        'default' => 'Outros',
        'order' => [
            'Saúde & Versão',
            'Autenticação',
            'Perfil do Usuário',
            'Lojas',
            'Dashboards',
            'Vendas',
            'Turnos de Caixa',
            'Fechamento de Caixa',
            'Regras de Bônus',
            'Regras de Comissão',
            'Metas Mensais',
            'Extrato Financeiro',
            'People Analytics',
        ],
    ],

    'logo' => false,

    'last_updated' => 'Última atualização: {date:d/m/Y}',

    'examples' => [
        'faker_seed' => 1234,
        'models_source' => ['factoryCreate', 'factoryMake', 'databaseFirst'],
    ],

    'strategies' => [
        'metadata' => [
            ...Defaults::METADATA_STRATEGIES,
        ],
        'headers' => [
            ...Defaults::HEADERS_STRATEGIES,
            Strategies\StaticData::withSettings(data: [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
        ],
        'urlParameters' => [
            ...Defaults::URL_PARAMETERS_STRATEGIES,
        ],
        'queryParameters' => [
            ...Defaults::QUERY_PARAMETERS_STRATEGIES,
        ],
        'bodyParameters' => [
            ...Defaults::BODY_PARAMETERS_STRATEGIES,
        ],
        'responses' => configureStrategy(
            Defaults::RESPONSES_STRATEGIES,
            Strategies\Responses\ResponseCalls::withSettings(
                only: ['GET *'],
                config: [
                    'app.debug' => false,
                ]
            )
        ),
        'responseFields' => [
            ...Defaults::RESPONSE_FIELDS_STRATEGIES,
        ]
    ],

    'database_connections_to_transact' => [config('database.default')],

    'fractal' => [
        'serializer' => null,
    ],
];
