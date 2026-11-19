# Contrato Completo da API — TV Digital Signage (`/api/v1`)

Todos os 19 endpoints utilizam prefixo `/api/v1`. As rotas de runtime do player são públicas (middleware `api`); as rotas administrativas exigem `auth:sanctum` (ver achado P0 em [SECURITY_AUDIT.md](./SECURITY_AUDIT.md) sobre ausência de middleware de perfil/permissão).

## Resolução de `{slugOrToken}`

Nos endpoints `/tv/playlist`, `/tv/ping` e `/tv/impression`, o identificador é resolvido nesta ordem em `tv_screens`:
1. `slug` (ex.: `loja-loja-9-mc-tabuleiro`, gerado automaticamente como `'loja-' . Str::slug($store->name)`)
2. `screen_key` (ex.: `scr_xxxxxxxxxxxxxxxx`)
3. `access_token` (ex.: `tk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`)
4. **Apenas em `/tv/playlist`:** se nenhum `TvScreen` for encontrado e `{slugOrToken}` for numérico, faz fallback para `Store::find((int) $slugOrToken)`.

> **Nota:** em `/tv/error/{slugOrToken}`, a consulta verifica apenas `slug` e `screen_key` (não verifica `access_token`).

---

## A. Endpoints Públicos do Player (4)

### 1. `GET /api/v1/tv/playlist/{slugOrToken}`
- **Controller:** `TvRuntimeController@playlist`
- **Middleware:** `api` (público)
- **Leituras:** `tv_screens`, `stores`, `tv_global_settings`, `tv_ads`, `tv_ad_store_targets` | **Escritas:** nenhuma
- **Frontend:** `tvService.getPlaylist()` ← `useTvPlaylist()` (polling a cada 3s)
- **Resposta `200 OK`:**
  ```json
  {
    "success": true,
    "data": {
      "screen": {
        "name": "TV - Loja 1",
        "store_id": 1,
        "store_name": "Loja 1",
        "orientation": "auto",
        "slug": "loja-loja-1",
        "is_blackout": false
      },
      "design_template": { "clock": {}, "store_badge": {}, "ticker_banner": {}, "branding_logo": {}, "qr_code": {} },
      "realtime": { "provider": "ably", "channel": "tv:screen:scr_..." },
      "playlist_count": 2,
      "playlist": [
        { "id": 10, "title": "Banner", "type": "image", "url": "https://.../storage/tv-ads/x.jpg", "thumbnail_url": "...", "duration": 10, "orientation": "all", "is_global": true }
      ],
      "server_time": "2026-11-19T15:00:00.000000Z"
    }
  }
  ```

### 2. `POST /api/v1/tv/ping/{slugOrToken}`
- **Controller:** `TvRuntimeController@ping`
- **Middleware:** `api` (público)
- **Body (opcional):** `width`, `height`, `current_ad_id`, `current_ad_title`, `fps`, `uptime_seconds`, `offline_cached`
- **Validações:** nenhuma explícita no controller
- **Leituras:** `tv_screens`, `stores`, `tv_screen_sessions` | **Escritas:** atualiza `tv_screens` (`is_online=true`, `last_ping_at`, telemetria, `device_info`); se `!is_blackout`, cria/atualiza `tv_screen_sessions` (fecha sessão anterior como `timeout_disconnected` se `last_ping_at < now()-5min`) e grava `tv_logs` (`tv_turned_on`) ao iniciar nova sessão.
- **Frontend:** `tvService.ping()` em `TvPlayer.tsx`
- **Resposta `200 OK`:** `{ "success": true, "status": "online", "server_time": "..." }`

### 3. `POST /api/v1/tv/impression/{slugOrToken}`
- **Controller:** `TvRuntimeController@impression`
- **Middleware:** `api` (público)
- **Body & Validações:** `tv_ad_id` (`required|integer|exists:tv_ads,id`), `duration_played_seconds` (`nullable|integer`), `completed_naturally` (`nullable|boolean`), `media_type` (`nullable|in:image,video`)
- **Escritas:** `tv_ad_impressions`
- **Códigos HTTP:** `200 OK`, `422 Unprocessable Entity`
- **Frontend:** `tvService.logImpression()` em `TvPlayer.tsx` (`advanceToNext`)

### 4. `POST /api/v1/tv/error/{slugOrToken}`
- **Controller:** `TvRuntimeController@logError`
- **Middleware:** `api` (público)
- **Body:** `message` (string, default `'Erro no player de TV'`), `url` (opcional), `type` (opcional)
- **Escritas:** `tv_logs` (`level='error'`, `event='tv_player_error'`)
- **Frontend:** `tvService.logError()` em `TvPlayer.tsx` (`handleVideoError`)
- **Resposta `200 OK`:** `{ "success": true }`

---

## B. Endpoints Administrativos (15)

Todos sob prefixo `/api/v1/admin/tv` com middleware `api` + `auth:sanctum` (HTTP `401` sem token válido).

| # | Método | Rota | Controller@Método | Query / Body Principal | Escritas / Efeitos | Códigos HTTP | Chamada Frontend |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 5 | `GET` | `/admin/tv/ads` | `TvAdController@index` | Query: `status`, `media_type`, `store_id`, `search`, `per_page` (default 50), `page` | Leitura (`tv_ads`, `tv_ad_impressions`, check disco `media_exists`) | `200`, `401` | `tvService.listAds` / `useTvAds` |
| 6 | `POST` | `/admin/tv/ads/upload-chunk` | `TvAdController@uploadChunk` | Multipart: `upload_id` (max 64), `chunk_index` (>=0), `total_chunks` (>=1), `filename` (max 255), `file` (max 10240 KB) | Grava chunk em `storage/app/temp/chunks/{upload_id}/`; quando completo unifica em `storage/app/public/tv-ads/` | `200`, `401`, `422` | `uploadMediaChunked()` |
| 7 | `POST` | `/admin/tv/ads` | `TvAdController@store` | `title` (req, max 150), `file` (`mimes:jpeg,png,jpg,webp,mp4,webm,mov|max:102400`) OU `media_url`+`media_path`, `media_type`, `thumbnail_url`, `duration_seconds` (3..600), `target_orientation` (`all\|horizontal\|vertical`), `is_global`, `status` (`active\|inactive\|scheduled`), `starts_at`, `ends_at`, `days_of_week[]`, `store_ids[]`, `order_priority` | Salva arquivo (se direto), cria `tv_ads`, sincroniza `tv_ad_store_targets`, grava `tv_logs` (`ad_created`), chama `notifyScreens()` | `201`, `401`, `422`, `500` | `tvService.createAd` / `useCreateTvAd` |
| 8 | `PUT` | `/admin/tv/ads/{id}` | `TvAdController@update` | Mesmos campos opcionais (suporta `POST` com `_method=PUT` para multipart); `status` aceita também `expired` | Substitui arquivo anterior no disco `public`, atualiza `tv_ads` e `tv_ad_store_targets`, grava `tv_logs` (`ad_updated`), chama `notifyScreens()` | `200`, `401`, `404`, `422` | `tvService.updateAd` / `useUpdateTvAd` |
| 9 | `DELETE` | `/admin/tv/ads/{id}` | `TvAdController@destroy` | Path: `id` | Apaga mídia do disco `public`, soft-delete em `tv_ads`, grava `tv_logs` (`ad_deleted`), chama `notifyScreens()` | `200`, `401`, `404` | `tvService.deleteAd` / `useDeleteTvAd` |
| 10 | `GET` | `/admin/tv/screens` | `TvAdController@screens` | — | **Efeito colateral:** executa `TvScreen::firstOrCreate` para cada `Store` cadastrada; calcula `is_online` (`last_ping_at > now()-2min`) | `200`, `401` | `tvService.listScreens` / `useTvScreens` (15s) |
| 11 | `POST` | `/admin/tv/screens/{screenId}/command` | `TvAdController@sendCommand` | `command`: `reload\|next\|pause\|play\|clear_cache\|turn_off\|turn_on\|toggle_power` | Atualiza `tv_screens.is_blackout` (para comandos de energia); `turn_off` encerra `TvScreenSession` ativa (`standby_turned_off`); grava `tv_logs` (`tv_remote_command`); publica Ably (`wheel:screen:{screen_key}`) | `200`, `401`, `404`, `422` | `tvService.sendCommand` / `useTvSendCommand` |
| 12 | `GET` | `/admin/tv/stats` | `TvAdController@stats` | — | Leitura agregada (`tv_ads`, `tv_screens`, `tv_ad_impressions` hoje, 15 últimos `tv_logs`) | `200`, `401` | `tvService.getStats` / `useTvStats` (15s) |
| 13 | `GET` | `/admin/tv/analytics` | `TvAdController@analytics` | Query: `store_id` (int ou `'all'`), `period` (`today\|7days\|30days`) | Leitura agregada (`tv_screen_sessions`, `tv_ad_impressions` com `DAYOFWEEK()` e `HOUR()` MySQL/MariaDB) | `200`, `401` | `tvService.getAnalytics` / `useTvAnalytics` (15s) |
| 14 | `GET` | `/admin/tv/logs` | `TvAdController@logs` | Query: `level`, `event`, `screen_slug`, `store_id`, `per_page` (default 50), `page` | Leitura paginada de `tv_logs` com `store:id,name` | `200`, `401` | `tvService.getLogs` / `useTvLogs` (10s) |
| 15 | `GET` | `/admin/tv/design` | `TvAdController@getDesignSettings` | — | Leitura de `tv_global_settings`, `stores`, `tv_screens` (merge `array_replace_recursive`) | `200`, `401` | `tvService.getDesignSettings` / `useTvDesignSettings` |
| 16 | `POST` | `/admin/tv/design/global` | `TvAdController@saveGlobalDesign` | Body: `{ "template": { ... } }` | `updateOrInsert` em `tv_global_settings` (`key='default_design_template'`), grava `tv_logs`, chama `notifyScreens()` | `200`, `401` | `tvService.saveGlobalDesign` / `useSaveGlobalDesign` |
| 17 | `POST` | `/admin/tv/design/stores/{storeId}` | `TvAdController@saveStoreDesign` | Path `storeId`; Body `{ "template": { ... } }` | `firstOrCreate` `TvScreen` da loja, salva `design_template`, grava `tv_logs`, chama `notifyScreens()` | `200`, `401`, `404` | `tvService.saveStoreDesign` / `useSaveStoreDesign` |
| 18 | `DELETE` | `/admin/tv/design/stores/{storeId}` | `TvAdController@resetStoreDesign` | Path `storeId` | Define `tv_screens.design_template = null`, grava `tv_logs`, chama `notifyScreens()` | `200`, `401`, `404` | `tvService.resetStoreDesign` / `useResetStoreDesign` |
| 19 | `POST` | `/admin/tv/design/upload-logo` | `TvAdController@uploadLogo` | Multipart: `file` (`required\|file\|mimes:png,jpg,jpeg,webp,svg\|max:10240`) | Salva em `storage/app/public/tv/logos/`, grava `tv_logs` (`tv_logo_uploaded`) | `200`, `401`, `422` | `tvService.uploadLogo` / `useUploadTvLogo` |
