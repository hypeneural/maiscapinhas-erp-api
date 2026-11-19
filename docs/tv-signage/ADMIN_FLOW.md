# Fluxo do Painel Administrativo (`/admin/tv`)

Acessível em `/admin/tv` (e alias `/config/tv`) pelo menu lateral **TVs & Anúncios → Anúncios de Loja** para perfis `admin`, `gerente` e `super_admin`.

## 1. Indicadores Superiores (KPIs em Tempo Real — `GET /admin/tv/stats`, 15s)
- **Telas Ativas:** `online_screens / total_screens` (online = `last_ping_at > now() - 2min`).
- **Anúncios Ativos:** quantidade de anúncios que satisfazem `scopeCurrentlyActive()`.
- **Visualizações Hoje:** contagem de `tv_ad_impressions` desde `00:00`.
- **Tempo no Ar Hoje:** soma de `duration_played_seconds` convertida em minutos.

## 2. Aba 1 — Grade de Anúncios (`TvAdsManager.tsx`)
- **Filtros:** busca por título, tipo (`all` / `image` / `video`), loja (`all` ou `store_id`), status (`all` / `active` / `inactive`).
- **Novo Anúncio:**
  - Suporta fotos (`JPG`, `PNG`, `WEBP`) e vídeos (`MP4`, `WEBM`).
  - **Upload automático inteligente:** se for vídeo **ou** arquivo `> 1,5 MB`, aciona `uploadMediaChunked()` (`src/lib/upload/chunkedUpload.ts`), enviando blocos de **2 MB** para `POST /admin/tv/ads/upload-chunk` com barra de progresso em tempo real e até 3 retentativas por parte; ao concluir, envia `POST /admin/tv/ads` com `media_url` e `media_path`. Arquivos menores vão via `FormData` direto em `POST /admin/tv/ads`.
  - **Segmentação:** switch **"Exibir em Todas as Lojas"** (`is_global = true`) ou seleção múltipla de filiais específicas (`store_ids[]`).
  - **Configuração:** duração do slide (3–300s na UI, 3–600s na API), orientação alvo (`all`, `horizontal`, `vertical`) e agendamento opcional (`starts_at`, `ends_at`).
- **Ações no Card:** pré-visualização ampliada (clique na mídia), switch rápido Ativo/Pausado, modal **Editar** (permite trocar mídia, duração, status, lojas e datas) e botão **Excluir** (soft-delete + remoção do arquivo).
- **Lacunas operacionais observadas (Backlog P3):** ainda não há interface visual para configurar `order_priority` (reordenação drag-and-drop) nem `days_of_week`, embora o backend já suporte ambos os campos.

## 3. Aba 2 — TVs & Controle Remoto (`useTvScreens`, 15s)
- Lista todas as lojas com status (`🟢 Online`, `⚪ Desconectada` ou `⚫ Standby`), FPS, anúncio em reprodução no momento e link público `{origin}/tv/{slug}`.
- **Ações por loja:**
  - Botão rápido **Desligar (Standby)** / **Ligar TV** (`turn_off` / `turn_on`).
  - Menu **Controle:** `Recarregar Grade`, `Pular Slide`, `Congelar/Pausar`, `Continuar`, `Limpar Cache Offline`.
  - **Copiar Link**, **Simular TV** (abre modal com iframe do player local) e **Abrir** (nova aba).

## 4. Aba 3 — Estúdio de Design (`TvDesignStudio.tsx`)
- Permite alternar o escopo de edição entre **Padrão da Rede (Global)** e **Override individual por Filial** (com botão **Restaurar Padrão Global**).
- **4 Presets prontos:** *Minimalist Dark*, *Cyberpunk Neon*, *Varejo & Oferta Gold* e *Mais Capinhas Brand*.
- **Preview WYSIWYG ao vivo** com alternância de orientação (**Horizontal 16:9** e **Vertical 9:16**) e 3 fundos de simulação.
- **Upload de logomarca:** aciona `POST /admin/tv/design/upload-logo` *(bug corrigido na recuperação do frontend em `tv.service.ts`, onde `API_BASE_URL` e `getAuthToken` não estavam importados)*.

## 5. Aba 4 — Estatísticas & Uptime (`TvStoreAnalytics.tsx`, 15s)
- Filtro por filial (`Todas as Lojas` ou loja específica) e período (`Hoje`, `Últimos 7 Dias`, `Últimos 30 Dias`).
- 5 KPIs: Horas Ligada, Média h/dia, Score de Disponibilidade (base 12h/dia), Exibições e Turnos Registrados.
- 4 gráficos Recharts: Exibições por Dia da Semana, Tempo Ativo por Dia, Distribuição por Horário (`00h`–`23h`) e Pizza Fotos vs Vídeos.
- Tabelas: Desempenho por Anúncio e Histórico de Turnos (`tv_screen_sessions`).

## 6. Aba 5 — Logs & Auditoria (`useTvLogs`, 10s)
- Filtros por loja e severidade (`info`, `warning`, `error`).
- Exibe eventos humanizados (`Novo Anúncio Publicado`, `Anúncio Modificado`, `Anúncio Excluído`, `Comando Remoto Enviado`, `Alerta na Reprodução da TV`) com visualização expansível do JSON técnico de `context`.
