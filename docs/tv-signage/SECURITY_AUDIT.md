# Auditoria de Segurança — TV Digital Signage

> **Escopo:** Auditoria estática e funcional do código recuperado fielmente de produção (`19/11/2026`). Nenhuma alteração de comportamento foi aplicada durante a fase de recuperação; todas as correções estão priorizadas em [IMPROVEMENT_BACKLOG.md](./IMPROVEMENT_BACKLOG.md).

## Resumo de Riscos Identificados

| ID | Severidade | Componente | Achado |
| --- | --- | --- | --- |
| SEC-01 | **CRÍTICA (P0)** | `routes/api_v1.php:362,421` & `TvAdController` | Grupo `/api/v1/admin/tv/*` possui **apenas `auth:sanctum`** — sem middleware de role/permissão no backend. Qualquer usuário autenticado (incluindo `vendedor`) pode criar/apagar anúncios, desligar TVs ou enviar chunks. |
| SEC-02 | **CRÍTICA (P0)** | `TvAdController.php:97-182` (`uploadChunk`) | **Sem whitelist de extensão nem validação MIME real** no arquivo unificado (`$ext = pathinfo($originalFilename, PATHINFO_EXTENSION)`). Permite gravar arquivos arbitrários (ex.: `.php`, `.html`, `.svg`) em `storage/app/public/tv-ads/`. Além disso, `upload_id` sanitizado pode resultar em string vazia `""`, não há limite máximo de `total_chunks` nem de tamanho total acumulado, e `chunk_index >= total_chunks` não é bloqueado. |
| SEC-03 | **CRÍTICA (P0)** | `TvAdController.php:215,352,424` (`store`/`update`/`destroy`) | `media_path` é aceito diretamente do payload JSON sem validar prefixo `tv-ads/` ou travessia de diretórios (`..`). Em `update()` e `destroy()`, `Storage::disk('public')->delete($ad->media_path)` pode apagar arquivos arbitrários dentro de `storage/app/public/` (ex.: fotos de usuários, capas personalizadas, comprovantes de caixa). |
| SEC-04 | **ALTA (P0)** | `routes/api_v1.php:91-96` & `TvRuntimeController` | Endpoints públicos `/tv/playlist`, `/tv/ping`, `/tv/impression` e `/tv/error` **não possuem rate limiting (`throttle`)** e aceitam slugs previsíveis (`loja-loja-9-mc-tabuleiro`) ou até IDs numéricos de loja (`/tv/1`, `/tv/2`) sem exigir `access_token`. Permite enumeração de lojas/anúncios e envenenamento ilimitado de `tv_ad_impressions`, `tv_screen_sessions` e `tv_logs`. |
| SEC-05 | **ALTA (P0)** | `TvAdController.php:979` (`uploadLogo`) | Aceita upload de arquivos **`.svg`** servidos diretamente em `/storage/tv/logos/` — vetor clássico de **Stored XSS** se um SVG malicioso for aberto no domínio da API. |
| SEC-06 | **MÉDIA (P1)** | `TvAdController.php:475` (`screens`) | `GET /admin/tv/screens` retorna `access_token` em texto claro na listagem de telas para qualquer usuário autenticado. |
| SEC-07 | **MÉDIA (P1)** | `TvAdController.php:286-294` (`store`) | Bloco `catch (\Throwable $e)` retorna `$e->getMessage()` diretamente no JSON HTTP 500 e grava stack trace completo em `tv_logs.context` (visível na aba Logs da UI). |
| SEC-08 | **MÉDIA (P1)** | `TvAdController.php:892,919` (`saveGlobalDesign`/`saveStoreDesign`) | Persiste `$request->input('template', [])` sem validação de schema, tipos ou URLs (`branding_logo.logo_url`, `qr_code.custom_url` aceitam qualquer string, incluindo `javascript:`). |
| SEC-09 | **MÉDIA (P1)** | `TvRuntimeController.php:290-305` (`logError`) | Não valida comprimento de `message` (`VARCHAR(255)` em `tv_logs`), podendo causar erro SQL 500; também não aceita `access_token` no lookup. |
| SEC-10 | **MÉDIA (P1)** | `AppServiceProvider.php` / `User.php` / `MeController.php` | `Gate::before` + `User::isSuperAdmin()` concedem bypass total para usuários com role Spatie `super_admin` OU coluna `is_super_admin = true`. Garantir proteção rígida na atribuição da role `super_admin` no módulo de papéis/usuários. |
| SEC-11 | **BAIXA (P2)** | `config/cors.php:42` | `max_age => 0` desabilita cache de preflight `OPTIONS`, dobrando o volume de requisições CORS em produção. |
| SEC-12 | **INFO (P1)** | `frontend/index.html` (CSP) | `script-src` mantém `'unsafe-inline' 'unsafe-eval'` (pré-existente) e `media-src`/`frame-src` foram ampliados para `https:` em produção. Recomenda-se restringir `media-src` e `frame-src` apenas às origens próprias (`'self' https://api.maiscapinhas.com.br`). |
| SEC-13 | **INFO (P1)** | Frontend `AuthContext`/`token.ts` em produção | A produção alterou armazenamento do token de `sessionStorage` para `localStorage` (mantido fora da branch de TV e documentado em `PRODUCTION_DIVERGENCES.md`). Tokens em `localStorage` elevam o impacto de eventual XSS. |

## Recomendações Imediatas (Sprint P0)
1. Adicionar middleware de permissão/role (ex.: `permission:screen.tv.ads` ou policy dedicada para `admin|gerente|super_admin`) em `/api/v1/admin/tv/*` e restringir gerentes às suas lojas vinculadas (`storeUsers`).
2. Em `uploadChunk`: impor whitelist estrita (`jpg,jpeg,png,webp,mp4,webm,mov`), validar MIME real via `finfo_file()` antes de mover para `public/tv-ads/`, rejeitar `upload_id` vazio/inválido, limitar `total_chunks <= 250` e tamanho total `<= 500 MB`.
3. Em `store()` / `update()`: validar que `media_path` corresponde estritamente a `^tv-ads/[A-Za-z0-9]{24}\.(jpg|jpeg|png|webp|mp4|webm|mov)$` e existe no disco `public`.
4. Aplicar `throttle` nos endpoints `/api/v1/tv/*` e introduzir validação de `access_token` (com período de transição compatível para slugs legados já configurados nas TVs das lojas).
5. Remover `svg` de `uploadLogo` (aceitar apenas `png,jpg,jpeg,webp`) e validar schema/URLs (`https://`) nos templates de design.
