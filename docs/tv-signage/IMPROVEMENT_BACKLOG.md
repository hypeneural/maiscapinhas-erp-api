# Backlog Priorizado de Melhorias Futuras — TV Digital Signage (P0–P4)

> Princípio arquitetural: manter a simplicidade do monólito **Laravel 12 + React 18/Vite** existente, sem microserviços ou dependências desnecessárias, focando em segurança, estabilidade das TVs e facilidade diária para o gestor.

---

## P0 — Segurança e Funcionamento Crítico (Executar Primeiro)

1. **Corrigir bug do Heartbeat a cada 1 segundo em `TvPlayer.tsx` (`SEC/PERF`):**
   - Remover `uptimeSeconds` (e demais campos mutáveis) do array de dependências do `useEffect` de `sendPing()`, lendo valores via `useRef`. Reduz em **96,7% (30×)** as requisições `POST /tv/ping` e preflights `OPTIONS` no servidor e no MariaDB.
2. **Proteger rotas `/api/v1/admin/tv/*` no backend (`SEC-01`):**
   - Aplicar middleware de autorização (`permission:screen.tv.ads` ou policy dedicada para `super_admin`/`admin`/`gerente`) e restringir gerentes às lojas em que possuem vínculo (`storeUsers`).
3. **Hardening de `uploadChunk()` (`SEC-02`):**
   - Impor whitelist estrita de extensões (`jpg,jpeg,png,webp,mp4,webm,mov`), validar MIME real via `finfo_file()` após montagem, rejeitar `upload_id` vazio/inválido, validar `0 <= chunk_index < total_chunks`, limitar `total_chunks` e tamanho máximo total, e criar rotina de limpeza de diretórios órfãos em `storage/app/temp/chunks/`.
4. **Blindar `media_path` contra exclusão arbitrária (`SEC-03`):**
   - Em `store()` e `update()`, validar estritamente que `media_path` segue `^tv-ads/[A-Za-z0-9]{24}\.[a-z0-9]{3,4}$` e pertence ao disco `public`.
5. **Rate limiting e proteção de endpoints públicos `/tv/*` (`SEC-04`, `SEC-09`):**
   - Adicionar `throttle` dedicado às rotas `/tv/*`, validar comprimento de `message` (max 255) em `logError`, aceitar `access_token` em `logError`, e planejar migração gradual para autenticação via `access_token` (header ou query assinada) desativando enumeração por ID numérico `/tv/1`.
6. **Remover `svg` de `uploadLogo()` e validar schema do template de design (`SEC-05`, `SEC-08`):**
   - Permitir apenas imagens raster (`png,jpg,jpeg,webp`) em `uploadLogo` e validar campos/URLs (`https://`) em `saveGlobalDesign`/`saveStoreDesign`.
7. **Não vazar stack traces / `access_token` (`SEC-06`, `SEC-07`):**
   - Remover `access_token` de `GET /admin/tv/screens` (exibir sob demanda) e retornar mensagem genérica no erro 500 de `store()`.

---

## P1 — Estabilidade e Confiabilidade das Televisões

1. **Persistência offline da playlist (`localStorage` / IndexedDB):**
   - Salvar último JSON válido de `/tv/playlist/{slug}` localmente para que a TV continue rodando mídias cacheadas mesmo se perder internet durante um reinício de energia.
2. **Prevenção de vazamento de memória no `tvCacheEngine.ts`:**
   - Revogar `URL.createObjectURL()` e remover entradas obsoletas do Cache Storage quando anúncios saírem da playlist (crucial para TV Boxes rodando 24/7).
3. **Handler `onError` em imagens + backoff exponencial em falhas consecutivas:**
   - Tratar falha de carregamento em `<img>` (registrando `/tv/error` e pulando slide) e aplicar backoff se 100% dos slides falharem (evitando loop infinito rápido).
4. **Comandos remotos confiáveis sem depender do Ably:**
   - Persistir fila leve de comandos pendentes (`tv_screen_commands` ou coluna `pending_command` + `command_version` em `tv_screens`) entregue no próprio polling da playlist — faz `reload`, `next`, `pause`, `play` e `clear_cache` funcionarem em 100% das TVs reais sem custo de broker externo.
5. **Corrigir `toggle_power` em `TvAdController::sendCommand()`:**
   - Encerrar `TvScreenSession` ativa ao alternar para `is_blackout = true` (paridade com `turn_off`).
6. **Corrigir sobreposição visual do QR Code com o Ticker no modo vertical:**
   - Ajustar margem inferior dos widgets `bottom-left` / `bottom-right` quando `ticker_banner.enabled === true`.

---

## P2 — Performance e Redução de Consumo

1. **Aumentar `max_age` em `config/cors.php` (ex.: `86400`):**
   - Elimina praticamente 100% das requisições `OPTIONS` de preflight repetitivas.
2. **Reduzir frequência de polling da playlist + cache/ETag:**
   - Alterar `useTvPlaylist` de `3s` para `15–30s` (ou `ETag`/`If-None-Match` retornando `304 Not Modified`) + cache Laravel de 15s invalidado em `store`/`update`/`destroy`/design/blackout.
3. **Retenção e agregação de `tv_ad_impressions` e `tv_logs`:**
   - Hoje `tv_ad_impressions` já soma **320k linhas / 67 MB em 3 meses**. Criar comando Artisan agendado para agregar métricas diárias e expurgar impressões/logs detalhados com mais de 90 dias.
4. **Otimizações de SQL e índices:**
   - Adicionar índices em `tv_ad_impressions(displayed_at)` e `tv_screens(access_token)`.
   - Eliminar N+1 (`firstOrCreate` dentro de loop) em `GET /admin/tv/screens`, separando provisionamento de tela da consulta de leitura.
   - Corrigir `$totalSessionsCount` em `analytics()` (contar antes do `limit(30)`).
5. **Limpeza segura de mídias órfãs no disco `public/tv-ads/`:**
   - Comando Artisan `--dry-run` para listar e remover arquivos não referenciados por nenhum `TvAd` ativo.
6. **Limpeza dos >8.000 assets antigos em `erp.maiscapinhas.com.br/assets/`** (~212 MB de builds históricos acumulados).

---

## P3 — Facilidade Operacional para o Gestor

1. **Reordenação visual (drag-and-drop) da grade (`order_priority`):**
   - Backend já ordena por `order_priority`; adicionar endpoint `POST /admin/tv/ads/reorder` e arrastar/soltar na aba Grade.
2. **Seletor de dias da semana (`days_of_week`) na UI:**
   - Backend já filtra por `days_of_week`; expor botões `Seg..Dom` nos modais de criação e edição.
3. **Upload múltiplo (lote) com drag-and-drop e detecção automática de duração de vídeo:**
   - Ler `video.duration` no navegador ao selecionar MP4 para preencher `duration_seconds` automaticamente e permitir subir várias fotos de uma campanha de uma vez.
4. **Status online/offline em resumo rápido por loja + alerta de TV desconectada há >15min em horário comercial.**
5. **Ajuste de responsividade da barra de abas e tabela de TVs em telas de 1280px.**

---

## P4 — Melhorias Visuais e Novas Funcionalidades

1. **Programação por faixa horária (`start_time` / `end_time`):** exibir ofertas de manhã/tarde/noite automaticamente.
2. **Playlists / Campanhas nomeadas com duplicação rápida** (ex.: Black Friday, Natal, Dia das Mães).
3. **Preview fiel com moldura 16:9 e 9:16 rodando a grade real da loja selecionada.**
4. **Geração automática de miniatura (thumbnail)** para vídeos no momento do upload (canvas frame capture no browser) para cards mais leves na aba Grade.
5. **QR Code inteligente vinculado automaticamente ao WhatsApp da loja (`stores.whatsapp`).**
