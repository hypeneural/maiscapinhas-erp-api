# Runbook de Rollback — TV Digital Signage

> **Regra de ouro:** em caso de incidente após deploy, realizar rollback **apenas de código/assets**. **NUNCA** executar `php artisan migrate:rollback`, `migrate:fresh` ou `DROP TABLE` em produção, pois `tv_ads`, `tv_screens`, `tv_ad_impressions`, `tv_screen_sessions` e `tv_logs` contêm histórico operacional ativo.

## 1. Gatilhos de Rollback

Acionar rollback imediatamente se ocorrer:
- Falha HTTP `500` em `GET /api/v1/tv/playlist/*` ou `POST /api/v1/tv/ping/*` impedindo reprodução nas lojas.
- Quebra de login (`/api/v1/auth/login` ou `/api/v1/me`) ou de módulos críticos do ERP (PDV, Conferência, Pedidos).
- Erro fatal JS/CSP no carregamento de `https://erp.maiscapinhas.com.br/tv/*` ou `/admin/tv`.

## 2. Rollback do Backend (`api.maiscapinhas.com.br`)

1. Restaurar os arquivos de código a partir do backup pré-deploy (ou fazer `git revert` do commit de merge na `main` e re-sincronizar via Plesk).
2. Garantir preservação intacta de `.env`, `.user.ini`, `public/.user.ini`, `public/storage` e `storage/app/public/tv-ads/`.
3. Reconstruir caches de configuração e rotas:
   ```bash
   php artisan optimize:clear
   php artisan config:cache
   php artisan route:cache
   ```
4. Validar `GET /api/v1/me` e `GET /api/v1/tv/playlist/{slug}` retornando `200 OK`.

## 3. Rollback do Frontend (`erp.maiscapinhas.com.br`)

1. Restaurar `index.html`, `sw.js`, `workbox-*.js`, `manifest.webmanifest` e pasta `assets/` do backup pré-deploy (o build estável anterior de `15/08/2026` tem `index-BAS5EoBj.js`, `TvAdsManager-xGnUr_CR.js`, `TvOverlayWidgets-T1nWZZ1Q.js`, `TvPlayer-BaDDonLb.js`).
2. Como o Workbox utiliza `skipWaiting: true` e `clientsClaim: true`, os navegadores e TV Boxes atualizam o Service Worker automaticamente no próximo ciclo de recarga.
3. Validar carregamento de `https://erp.maiscapinhas.com.br/login` e de uma TV em modo somente leitura.

## 4. Pós-Incidente

- Capturar amostras dos erros de `laravel.log` e `error_log` (sanitizados).
- Reproduzir o erro no ambiente local/staging isolado (`MaisCapinhas/staging/`) e adicionar teste de regressão em `tests/Feature/Api/V1/TvSignageTest.php` antes de nova tentativa de deploy.
