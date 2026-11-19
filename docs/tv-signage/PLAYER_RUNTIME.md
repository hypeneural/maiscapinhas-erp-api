# Runtime do Player das Televisões (`/tv/:slug`)

## 1. Inicialização e Identificação da Loja

- **Rotas SPA públicas:** `/tv/:slug` (e fallback `/tv` → slug `'tv-default'`), renderizando `src/pages/tv/TvPlayer.tsx` sem `MainLayout` e sem exigência de autenticação.
- **Consulta de playlist:** hook `useTvPlaylist(screenSlug)` dispara `GET /api/v1/tv/playlist/{slug}` com `refetchInterval: 3000` (a cada 3 segundos) e `staleTime: 1000`.
- **Memoização:** usa `JSON.stringify(rawPlaylist)` para evitar reiniciar o slide quando o payload retornado pelo polling não mudou.

## 2. Pré-carregamento e Cache Local (`tvCacheEngine.ts`)

- Utiliza a **Cache Storage API** do navegador sob o bucket `maiscapinhas-tv-assets-v1` + `Map` em memória (`memoryBlobUrls`) com `URL.createObjectURL(blob)`.
- Ao atualizar a playlist ou trocar de slide, pré-carrega todos os itens em background (`preloadPlaylistAssets`) e prioriza os próximos 2 slides (`safeIndex + 1`, `safeIndex + 2`).
- **Limitação observada (Backlog P1/P2):**
  - `memoryBlobUrls` nunca revoga ObjectURLs de mídias removidas da playlist até que `clearTvCache()` seja chamado — risco de crescimento de memória RAM em Smart TVs/TV Boxes rodando por dias seguidos.
  - Vídeos grandes em `cache.put()` podem exceder quota do Cache Storage silenciosamente (fallback volta para URL remota).
  - Se a internet cair completamente antes de `playlistData` ser carregada, o player não persiste o JSON da playlist em `localStorage`/IndexedDB (mostra tela `"Falha de Comunicação com o Servidor"` até reconectar).

## 3. Reprodução, Transições e Recuperação de Falhas

- **Imagens:** renderizadas com `object-contain` + fundo ambiente borrado (`blur-3xl opacity-30`); avançam via `setTimeout(max(3, duration) * 1000)`.
- **Vídeos:** `<video autoPlay muted playsInline preload="auto" disablePictureInPicture>` com retry de autoplay em 300 ms; avança em `onEnded` ou via **watchdog de segurança** após `max(15, (duration || 30) + 8)` segundos caso o vídeo trave.
- **Erro de vídeo (`onError`):** envia `POST /tv/error/{slug}` e chama `advanceToNext(false)` automaticamente.
- **Erro de imagem:** atualmente `<img>` **não** possui handler `onError` (backlog P1).
- **Cursor:** oculta automaticamente (`cursor-none`) após 3 segundos sem movimento de mouse.

## 4. Overlays Configuráveis (`TvOverlayWidgets.tsx`)

Renderizados sobre o slide a partir de `design_template` (merge entre global e override da loja):
1. `TvClockWidget` — relógio 24h / 24h+segundos / 12h / data+hora, 4 cantos, 4 estilos (`glass`, `solid`, `neon`, `minimal`).
2. `TvStoreBadgeWidget` — nome da loja (ou customizado), tagline e indicador pulsante.
3. `TvTickerBannerWidget` — letreiro contínuo (marquee CSS) com fonte Google Fonts, velocidade, cor e prefixo.
4. `TvQrCodeWidget` — QR Code via `qrcode.react` (fallback URL `https://erp.maiscapinhas.com.br/tv/{slug}` — recomenda-se torná-lo configurável para WhatsApp da loja).
5. `TvBrandingLogoWidget` — logomarca posicionável com altura e opacidade.

## 5. Blackout, Comandos Remotos e Anomalias Críticas Identificadas

### Anomalia Crítica P0/P2 — Heartbeat disparando a cada 1 segundo (não a cada 30s!)
Em `TvPlayer.tsx` (linhas 83–107), o `useEffect` responsável por `sendPing()` declara `uptimeSeconds` no array de dependências — mas `uptimeSeconds` é incrementado **a cada 1 segundo**. Resultado: o efeito desmonta o `setInterval(30000)` e executa `POST /api/v1/tv/ping/{slug}` **a cada 1 segundo** (e como `config/cors.php` tem `max_age => 0`, gera também um preflight `OPTIONS` a cada segundo). Isso explica os **130 MB de `access_ssl_log` gerados em menos de 10 horas** em produção! Correção trivial planejada no Backlog P0/P2 (usar `useRef` para `uptimeSeconds` e estado atual).

### Comandos Remotos e Divergência Ably
- **`turn_off` / `turn_on` / `toggle_power`:** persistem `is_blackout` em `tv_screens`; o player detecta via polling de `/tv/playlist` em até 3s e entra/sai do modo Standby (tela preta). *(Nota: `toggle_power` no backend esquece de fechar a `TvScreenSession` ativa ao desligar, diferentemente de `turn_off`.)*
- **`reload`, `next`, `pause`, `play`, `clear_cache`:** funcionam via `window.postMessage` apenas no simulador iframe dentro do ERP. Em TVs reais, dependem do Ably — porém (a) `ABLY_KEY` não está configurada em produção, (b) `TvPlayer.tsx` não possui cliente Ably subscrito e (c) `AblyPublisher::publishToScreen` publica no canal `wheel:screen:{screen_key}` enquanto `playlist()` anuncia `tv:screen:{screen_key}`.
