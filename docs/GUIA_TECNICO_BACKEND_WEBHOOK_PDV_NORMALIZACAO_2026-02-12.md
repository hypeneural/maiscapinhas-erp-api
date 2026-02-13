# Guia Tecnico Backend PDV Webhook v3.0 - Ingestao, Normalizacao e Tabelas

Data: 2026-02-12  
Projeto: `maiscapinhas-erp-api`  
Escopo: explicar para o time do webhook (Python) como o backend recebe, valida, normaliza e persiste os dados PDV.

---

## 1) Arquitetura atual (resumo rapido)

Entrada publica:
- `POST /api/v1/pdv/sync`

Arquivos principais:
- Rota: `routes/api_v1.php:62`
- Auth webhook: `app/Http/Middleware/ValidatePdvSignature.php:15`
- Validacao de request: `app/Http/Requests/Pdv/PdvSyncIngestRequest.php:20`
- Ingestao/controller: `app/Http/Controllers/Api/V1/PdvSyncController.php:105`
- Processamento assinc (fila): `app/Jobs/ProcessPdvSyncJob.php:71`
- Config PDV: `config/pdv.php:5`
- Scheduler (consumo de fila por cron): `routes/console.php:43`

Estado de versao:
- Backend esta v3 only (`supported_schema_versions = ['3.0']`): `config/pdv.php:23`
- JSON schema ativo para v3: `config/pdv.php:25-27`

---

## 2) Fluxo ponta a ponta (o que acontece quando chega um JSON)

### 2.1 Entrada HTTP e autenticacao

1. Requisicao chega em `POST /api/v1/pdv/sync`.
2. Middleware `pdv.signature` valida autenticacao conforme `PDV_AUTH_MODE`.
3. Modos suportados: `hmac`, `bearer`, `auto`, `none`.

Referencia:
- `routes/api_v1.php:62-64`
- `app/Http/Middleware/ValidatePdvSignature.php:17-45`

### 2.2 Validacao de contrato

1. `PdvSyncIngestRequest` valida estrutura minima.
2. `schema_version` precisa ser suportado (`3.0`).
3. Campos principais: `store`, `window`, `integrity.sync_id`.
4. `canal` validado em `vendas.*.canal` e `snapshot_vendas.*.canal`.

Referencia:
- `app/Http/Requests/Pdv/PdvSyncIngestRequest.php:27-114`

### 2.3 Regras no controller (ingestao)

1. Extrai payload validado.
2. Faz idempotencia por `integrity.sync_id`:
- se ja existe -> retorna `200 duplicate`
- se nao existe -> continua
3. Valida header `X-PDV-Schema-Version` (se presente):
- precisa estar em versoes suportadas
- precisa bater com body `schema_version`
4. Valida JSON schema (`docs/schema_v3.0.json`) se habilitado.
5. Calcula risco inicial (`risk_flags`) e salva ingestao.
6. Salva payload bruto em `pdv_sync_payloads`.
7. Enfileira `ProcessPdvSyncJob`.

Referencia:
- `app/Http/Controllers/Api/V1/PdvSyncController.php:132-157` (idempotencia)
- `app/Http/Controllers/Api/V1/PdvSyncController.php:160-198` (header/schema)
- `app/Http/Controllers/Api/V1/PdvSyncController.php:253-297` (risk flags)
- `app/Http/Controllers/Api/V1/PdvSyncController.php:361-407` (insert + queue)

### 2.4 Processamento assinc (job)

Pipeline interno do job:
1. lock por loja
2. decode payload raw
3. resolve contexto de loja
4. processa master data (lojas, usuarios, meios)
5. processa `turnos[]`
6. processa `snapshot_turnos[]`
7. processa `vendas[]`
8. processa `snapshot_vendas[]`
9. merge de risk flags runtime
10. marca sync como `processed`

Referencia:
- `app/Jobs/ProcessPdvSyncJob.php:95-146`

---

## 3) Como estamos normalizando os dados

### 3.1 Loja

- Chave operacional de origem: `store.id_ponto_venda`.
- Backend converte para `store_id` interno via `pdv_store_mappings` quando houver.
- Se nao houver mapeamento: marca `risk_flag=store_mapping_missing` e processa dados com `store_id = null`.

Referencia:
- `app/Jobs/ProcessPdvSyncJob.php:231-243`

### 3.2 Canal de venda (dual source)

- Canais aceitos: `HIPER_CAIXA` e `HIPER_LOJA`.
- Canal invalido cai em fallback `HIPER_CAIXA` + risk flag `venda_canal_invalid`.

Referencia:
- `app/Jobs/ProcessPdvSyncJob.php:1429-1452`

### 3.3 Itens/pagamentos com dedup robusta

- Preferencia por `line_id` quando presente.
- Fallback por `row_hash` quando `line_id` ausente.
- Sempre com `canal` na chave para evitar colisao cross-canal.

Referencia:
- `app/Jobs/ProcessPdvSyncJob.php:645-657` (itens)
- `app/Jobs/ProcessPdvSyncJob.php:659-695` (pagamentos)

### 3.4 Snapshots como auto-correcao

- `snapshot_turnos[]` faz upsert em `pdv_turnos`.
- `snapshot_vendas[]` faz upsert em `pdv_vendas_resumo`.
- Se coluna existir, atualiza `pdv_vendas.last_seen_in_snapshot_at`.

Referencia:
- `app/Jobs/ProcessPdvSyncJob.php:796-904` (snapshot turnos)
- `app/Jobs/ProcessPdvSyncJob.php:701-790` (snapshot vendas)
- `app/Jobs/ProcessPdvSyncJob.php:1575-1601` (last seen)

### 3.5 Autocadastro de dimensoes (master data)

- `pdv_lojas` a partir de `store.*`
- `pdv_usuarios` a partir de operador/responsavel/vendedor
- `pdv_meios_pagamento` a partir de pagamentos

Referencia:
- `app/Jobs/ProcessPdvSyncJob.php:1091-1124` (lojas)
- `app/Jobs/ProcessPdvSyncJob.php:1126-1275` (usuarios)
- `app/Jobs/ProcessPdvSyncJob.php:1277-1397` (meios)

### 3.6 Normalizacao tecnica (data/hora e numericos)

- Datetimes recebidos no JSON sao convertidos para UTC antes de persistir.
- Datetime sem timezone explicito usa timezone configurado (`pdv.naive_datetime_timezone`).
- Campos monetarios e quantidades sao normalizados em string decimal no job antes do upsert.

Referencia:
- `app/Support/Pdv/PdvDateTime.php:12-33`
- `app/Jobs/ProcessPdvSyncJob.php:1047-1066`

---

## 4) Mapa JSON -> Tabelas (o que vai para onde)

### 4.1 Tabelas de controle de ingestao

| Tabela | Origem JSON | Funcao | Chave unica |
|---|---|---|---|
| `pdv_syncs` | raiz (`sync_id`, store, window, ops, warnings, risk) | controle de entrada/fila/processamento | `sync_id` |
| `pdv_sync_payloads` | payload bruto | auditoria/reprocessamento | `pdv_sync_id` (1:1) |

### 4.2 Turnos

| Tabela | Origem JSON | Funcao | Chave UPSERT |
|---|---|---|---|
| `pdv_turnos` | `turnos[]` + `snapshot_turnos[]` | estado consolidado de turno | `(store_pdv_id, id_turno)` |
| `pdv_turno_pagamentos` | `totais_sistema.por_pagamento`, `fechamento_declarado.por_pagamento`, `falta_caixa.por_pagamento` | comparativo sistema x declarado x falta/sobra | `(store_pdv_id, id_turno, tipo, id_finalizador)` |

### 4.3 Vendas

| Tabela | Origem JSON | Funcao | Chave UPSERT |
|---|---|---|---|
| `pdv_vendas` | `vendas[]` | cabecalho de venda | `(store_pdv_id, canal, id_operacao)` |
| `pdv_venda_itens` | `vendas[].itens[]` | linhas de item | `(store_pdv_id, canal, line_id)` ou `(store_pdv_id, canal, id_operacao, row_hash)` |
| `pdv_venda_pagamentos` | `vendas[].pagamentos[]` | linhas de pagamento | `(store_pdv_id, canal, line_id)` ou `(store_pdv_id, canal, id_operacao, row_hash)` |
| `pdv_vendas_resumo` | `snapshot_vendas[]` | resumo rapido para reconciliacao/consulta | `(store_pdv_id, canal, id_operacao)` |

### 4.4 Tabelas de normalizacao

| Tabela | Origem | Funcao |
|---|---|---|
| `pdv_lojas` | `store.id_ponto_venda`, `store.nome`, `store.alias` | normalizacao de loja |
| `pdv_usuarios` | operador/responsavel/vendedor | normalizacao de usuarios PDV |
| `pdv_meios_pagamento` | `id_finalizador` + `meio` | normalizacao de finalizadores |
| `pdv_store_mappings` | cadastro backend | vincula loja PDV -> loja interna |
| `pdv_user_mappings` | cadastro backend | vincula usuario PDV -> user interno |

---

## 5) Chaves canonicas e motivo

- Venda: `(store_pdv_id, canal, id_operacao)`
- Item: `(store_pdv_id, canal, line_id)`
- Pagamento: `(store_pdv_id, canal, line_id)`

Motivo:
- `id_operacao` e `line_id` podem colidir entre `HIPER_CAIXA` e `HIPER_LOJA`.
- `canal` e obrigatorio para nao sobrescrever dados entre fontes.

Migracoes relevantes:
- `database/migrations/2026_02_12_000250_add_canal_to_pdv_vendas_table.php`
- `database/migrations/2026_02_12_000330_add_canal_to_pdv_venda_children_tables.php`

---

## 6) Risk flags e warnings (como diagnosticar)

Risk flags de entrada (controller):
- `store_mapping_missing`
- `store_alias_mismatch`
- `store_alias_mismatch_blocked`
- `timestamp_out_of_window`
- `timestamp_missing`
- `auth_bearer_fallback`
- `event_type_unknown`
- `event_type_turno_closure_with_vendas`
- `event_type_mixed_without_vendas`
- `event_type_mixed_without_closed_turno`
- `gestao_db_failure`
- `vendedor_null`
- `meio_pagamento_null`

Referencia:
- `app/Http/Controllers/Api/V1/PdvSyncController.php:271-297`
- `app/Http/Controllers/Api/V1/PdvSyncController.php:521-592`

Risk flags de runtime (job):
- `venda_canal_invalid`
- `snapshot_turno_malformed`
- `snapshot_venda_malformed`
- `user_mapping_missing`

Referencia:
- `app/Jobs/ProcessPdvSyncJob.php:814-833`
- `app/Jobs/ProcessPdvSyncJob.php:716-735`
- `app/Jobs/ProcessPdvSyncJob.php:1441-1451`
- `app/Jobs/ProcessPdvSyncJob.php:1487-1495`

---

## 7) Problemas atuais mais comuns e causa

### 7.1 `risk_flags: ["store_mapping_missing"]`

Causa:
- `store.id_ponto_venda` do payload nao esta mapeado em `pdv_store_mappings`.

Efeito:
- ingestao e aceita
- dados entram com `store_id = null`
- relatorios por loja interna podem nao mostrar tudo

Correcao:
- cadastrar mapeamento em `pdv_store_mappings` (ativo=true)

### 7.2 Sync fica `queued` por muito tempo

Causa comum:
- scheduler/cron nao executando `pdv:queue-consume`

Pontos para validar:
- `PDV_CRON_QUEUE_CONSUMER_ENABLED=true`
- `schedule:run` rodando a cada minuto
- fila redis acessivel

Referencia:
- `routes/console.php:43-53`
- `app/Console/Commands/PdvQueueConsumeCommand.php:28-104`

### 7.3 Header/body de schema divergente

Regra:
- se `X-PDV-Schema-Version` vier, precisa bater com `payload.schema_version`

Referencia:
- `app/Http/Controllers/Api/V1/PdvSyncController.php:176-186`

---

## 8) Queries SQL uteis para suporte rapido

Syncs recentes e status:
```sql
SELECT id, sync_id, schema_version, event_type, store_pdv_id, store_id, status, risk_flags, received_at, processed_at
FROM pdv_syncs
ORDER BY id DESC
LIMIT 50;
```

Syncs travados em fila:
```sql
SELECT id, sync_id, store_pdv_id, status, attempts, received_at, queued_at, processing_started_at
FROM pdv_syncs
WHERE status IN ('queued','processing')
ORDER BY id DESC;
```

Lojas PDV sem mapeamento interno:
```sql
SELECT DISTINCT s.store_pdv_id
FROM pdv_syncs s
LEFT JOIN pdv_store_mappings m ON m.pdv_store_id = s.store_pdv_id AND m.active = 1
WHERE m.id IS NULL;
```

Conferencia de vendas por canal:
```sql
SELECT store_pdv_id, canal, COUNT(*) qtd_vendas, SUM(total) total
FROM pdv_vendas
GROUP BY store_pdv_id, canal
ORDER BY store_pdv_id, canal;
```

---

## 9) Endpoints de consulta ja disponiveis para o time validar dados

Protegidos por `auth:sanctum`:
- `GET /api/v1/pdv/reports/turnos`
- `GET /api/v1/pdv/reports/vendas`
- `GET /api/v1/pdv/reports/ranking-vendedores`
- `GET /api/v1/pdv/reports/ranking-vendedor-loja`

Admin/observabilidade:
- `GET /api/v1/admin/pdv/syncs`
- `GET /api/v1/admin/pdv/syncs/metrics`

Referencias:
- `routes/api_v1.php:233-238`
- `routes/api_v1.php:314-317`

---

## 10) Contrato operacional que precisamos do time Python

Itens criticos para manter estabilidade:
- manter `schema_version = 3.0`
- manter `canal` sempre valido (`HIPER_CAIXA` ou `HIPER_LOJA`)
- manter `integrity.sync_id` deterministico por janela
- enviar `snapshot_turnos[]` e `snapshot_vendas[]` em todo payload
- manter `integrity.warnings[]` quando houver falha de gestao ou dados incompletos

Observacao:
- o backend aceita reenvio (idempotencia por `sync_id`) e corrige dados por snapshot via upsert.

---

## 11) Conclusao

Hoje o pipeline esta estruturado em 3 camadas:
1. Ingestao e seguranca (`middleware` + `request` + `controller`)  
2. Persistencia de auditoria (`pdv_syncs` + payload raw)  
3. Normalizacao e consolidacao (`ProcessPdvSyncJob` + upserts canonicamente versionados)

Isso permite:
- receber payloads em alta confiabilidade
- evitar duplicidade por chave canonica
- reconciliar divergencias recentes com snapshots
- operar relatorios por loja, canal, turno, vendedor e meio de pagamento

