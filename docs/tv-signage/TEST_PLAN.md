# Plano de Testes — TV Digital Signage

## 1. Isolamento Obrigatório

- **Banco de testes:** SQLite em memória (`:memory:`) ou base MySQL/MariaDB dedicada de testes (`maiscapinhas_erp_test`) — **nunca** banco de produção.
- **Serviços externos:** `AblyPublisher` sempre mockado nos testes automatizados e `ABLY_KEY=` vazia em staging local.
- **Frontend local/staging:** criar `.env.production.local` (ignorado pelo Git) com `VITE_API_URL=/api/v1` (ou URL localhost) e validar no bundle gerado que não há apontamento para `api.maiscapinhas.com.br`.

## 2. Testes Automatizados — Backend

```bash
# 1. Verificação de sintaxe PHP nos arquivos do módulo
php -l app/Http/Controllers/Api/V1/Admin/TvAdController.php
php -l app/Http/Controllers/Api/V1/Tv/TvRuntimeController.php

# 2. Conferência das 19 rotas registradas
php artisan route:list --path=api/v1/tv -v
php artisan route:list --path=api/v1/admin/tv -v

# 3. Suíte dedicada de TV Digital Signage (11 cenários, 152 asserções)
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test tests/Feature/Api/V1/TvSignageTest.php

# 4. Regressão completa da aplicação (em CI com MariaDB 10.6 / MySQL 8)
php artisan test
```

### Matriz de Cobertura (`tests/Feature/Api/V1/TvSignageTest.php`)
- Rejeição `401` em todas as rotas `/admin/tv/*` sem token Sanctum.
- Regressão `/api/v1/me`, `User::isSuperAdmin()` (flag booleana + role Spatie `super_admin`), `getStoresWithRoles()` e `Gate::before`.
- Cadastro de imagem (upload direto) e listagem paginada com `media_exists`.
- Cadastro de vídeo (upload direto).
- Upload em chunks (`POST /admin/tv/ads/upload-chunk` 2 partes -> montagem binária exata -> limpeza de chunks -> `POST /admin/tv/ads`).
- Validação `422` ao criar anúncio sem mídia.
- Edição (`PUT`) e exclusão (`DELETE` soft-delete + remoção do disco `public`).
- Distribuição global (`is_global=true`), segmentação por loja (`is_global=false`), **isolamento estrito entre lojas**, playlist vazia, filtragem de inativos/agendados/expirados/fora do `days_of_week`, e resolução por `slug`, `screen_key`, `access_token` e `store_id` numérico.
- Heartbeat (`POST /tv/ping`), abertura/extensão de `TvScreenSession`, encerramento por timeout (>5min `timeout_disconnected`), impressões (`POST /tv/impression` + `422` inválido), erros do player (`POST /tv/error`), `GET /admin/tv/stats`, `GET /admin/tv/analytics` e `GET /admin/tv/logs`.
- Design global (`GET/POST /admin/tv/design/global`), override individual (`POST /admin/tv/design/stores/{storeId}`), reset (`DELETE`), propagação para `/tv/playlist/{slug}` e upload de logo (`POST /admin/tv/design/upload-logo`).
- Blackout (`turn_off` encerrando sessão como `standby_turned_off`, `turn_on` restaurando), comandos simulados (`reload`, `next`, `pause`, `play`, `clear_cache`, `toggle_power`) com `AblyPublisher` mockado e rejeição `422` para comando inválido.

## 3. Testes Automatizados — Frontend

```bash
# Type-checking estrito da aplicação
npx tsc -p tsconfig.app.json --noEmit

# ESLint direcionado aos arquivos do módulo TV
npx eslint src/types/tv.types.ts src/services/tv.service.ts src/services/tvCacheEngine.ts src/lib/upload/chunkedUpload.ts src/hooks/api/use-tv-ads.ts src/pages/admin/tv src/pages/tv

# Build de produção + geração PWA
npm run build
```

## 4. QA Integrado em Navegador (Staging Local Isolado)

1. Inicializar SQLite sintético (`staging/setup_staging_env.php`) e subir backend local (`127.0.0.1:8000`) + proxy SPA same-origin (`127.0.0.1:8080`).
2. Autenticar com usuário sintético (`admin@staging.local`) e validar `/admin/tv`:
   - Aba **Grade:** listagem, criação com upload, segmentação por loja e edição.
   - Aba **TVs:** alternância Standby (`turn_off`) e Ligar TV (`turn_on`).
   - Aba **Estúdio de Design:** aplicação de preset, preview WYSIWYG e salvamento global.
   - Aba **Logs & Auditoria:** registro dos eventos gerados.
3. Abrir player fullscreen `/tv/loja-loja-1-centro-teste`:
   - Viewport horizontal (`1280×720` / `1920×1080`): exibição fullscreen, overlays (relógio, badge, QR code, ticker) e transição automática entre slides.
   - Viewport vertical (`540×960` / `1080×1920`): responsividade sem quebra de layout.
4. Confirmar na aba Network **zero chamadas** a `api.maiscapinhas.com.br` ou `erp.maiscapinhas.com.br`.
