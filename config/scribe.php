<?php

/**
 * Scribe API Documentation Configuration
 * 
 * NOTA: Este arquivo só é usado quando o Scribe está instalado (composer require --dev).
 * Em produção, o Scribe não está disponível e este arquivo não é carregado.
 */

// Só carrega as configurações se o Scribe estiver instalado
if (!class_exists(\Knuckles\Scribe\Config\Defaults::class)) {
    return [];
}

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
        curl -X POST https://api.maiscapinhas.com.br/api/v1/auth/login \\
          -H "Content-Type: application/json" \\
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
    INTRO,

    // URL base
    'base_url' => 'https://api.maiscapinhas.com.br',

    // Rotas a documentar
    'routes' => [
        [
            'match' => [
                'prefixes' => ['api/v1/*'],
                'domains' => ['*'],
            ],
            'include' => [],
            'exclude' => [
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
        'use_csrf' => false,
        'csrf_url' => '/sanctum/csrf-cookie',
    ],

    // Configuração de autenticação
    'auth' => [
        'enabled' => true,
        'default' => true,
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
            'PDV - Sync',
            'PDV - Relatorios',
            'PDV - Admin',
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
                // Avoid leaking production data in generated examples.
                // Keep response calls restricted to PDV endpoints (safe operational data) and public health/version.
                only: [
                    'GET api/v1/health',
                    'GET api/v1/version',
                    'GET api/v1/pdv/reports/*',
                    'GET api/v1/admin/pdv/*',
                ],
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
