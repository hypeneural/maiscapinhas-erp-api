# Analise Completa - Webhook PDV Sync Agent v3.0

Data: 2026-02-11  
Projeto: `maiscapinhas-erp-api`  
Escopo: comparar a documentacao enviada pelo time PDV (v3.0) com o que esta implementado no backend hoje.

---

## 1) Resumo executivo

O backend atual esta maduro para o contrato v2.x, com boa base de ingestao (idempotencia por `sync_id`, fila assincrona, lock por loja, observabilidade inicial), mas ainda **nao esta aderente ao contrato v3.0**.

Status geral de aderencia v3.0:
- Implementado: **4/10** novidades principais
- Parcial: **2/10**
- Nao implementado: **4/10**

Risco principal para v3.0:
- O modelo atual de vendas usa chave unica `store_pdv_id + id_operacao` sem `canal`, o que pode gerar colisao/sobrescrita no cenario dual-database (`HIPER_CAIXA` + `HIPER_LOJA`).

---

## 2) Evidencias tecnicas auditadas

Arquivos principais revisados:
- `app/Http/Controllers/Api/V1/PdvSyncController.php`
- `app/Http/Requests/Pdv/PdvSyncIngestRequest.php`
- `app/Jobs/ProcessPdvSyncJob.php`
- `config/pdv.php`
- `docs/schema_v2.0.json`
- `database/migrations/2026_02_10_000130_create_pdv_turnos_table.php`
- `database/migrations/2026_02_10_000150_create_pdv_vendas_table.php`
- `database/migrations/2026_02_10_000160_create_pdv_venda_itens_table.php`
- `database/migrations/2026_02_10_000170_create_pdv_venda_pagamentos_table.php`
- `database/migrations/2026_02_11_000190_add_schema_version_and_request_id_to_pdv_syncs_table.php`
- `database/migrations/2026_02_11_000240_add_event_type_to_pdv_syncs_table.php`
- `app/Http/Controllers/Api/V1/Admin/PdvSyncAdminController.php`
- `app/Console/Commands/PdvOpsMonitorCommand.php`
- `tests/Feature/Api/V1/PdvSyncWebhookTest.php`
- `tests/Feature/Api/V1/Admin/PdvSyncAdminControllerTest.php`
- `routes/api_v1.php`

---

## 3) Matriz de aderencia - Novidades v3.0

| Item v3.0 | Status | Evidencia | Observacao |
|---|---|---|---|
| Dual-database (Caixa + Loja) | Nao implementado | `app/Jobs/ProcessPdvSyncJob.php` | Pipeline so processa uma origem logica, sem separacao por canal. |
| `vendas[].canal` | Nao implementado | `database/migrations/2026_02_10_000150_create_pdv_vendas_table.php:25`, `app/Jobs/ProcessPdvSyncJob.php:553` | Nao existe coluna `canal`; upsert de venda usa `store_pdv_id + id_operacao`. |
| `snapshot_turnos[]` | Nao implementado | `app/Http/Requests/Pdv/PdvSyncIngestRequest.php`, `app/Jobs/ProcessPdvSyncJob.php` | Campo nao validado e nao processado. |
| `snapshot_vendas[]` | Nao implementado | `app/Http/Requests/Pdv/PdvSyncIngestRequest.php`, `app/Jobs/ProcessPdvSyncJob.php` | Campo nao validado e nao processado. |
| `ops.loja_count` + `ops.loja_ids` | Nao implementado | `app/Http/Requests/Pdv/PdvSyncIngestRequest.php`, `app/Http/Controllers/Api/V1/PdvSyncController.php:252` | Apenas `ops.count` e `ops.ids` sao lidos/persistidos. |
| `event_type` dinamico | Implementado | `app/Http/Controllers/Api/V1/PdvSyncController.php:33`, `database/migrations/2026_02_11_000240_add_event_type_to_pdv_syncs_table.php:14` | Suporta `sales`, `turno_closure`, `mixed`. |
| Turno-aware sync sem vendas | Implementado | `tests/Feature/Api/V1/PdvSyncWebhookTest.php:357` | Existe teste de `turno_closure` com `vendas=[]` e `ops.count=0`. |
| `turnos[].responsavel` | Nao implementado | `app/Jobs/ProcessPdvSyncJob.php:233` | Job so grava dados de `operador`, nao de `responsavel`. |
| Trigger on_shutdown (agente) | Fora do backend | N/A | Sem acao no backend; depende do agente emissor. |
| IDs universais + normalizacao | Parcial | `database/migrations/2026_02_10_000120_create_pdv_store_mappings_table.php`, `database/migrations/2026_02_11_000220_create_pdv_user_mappings_table.php` | Existe mapping tecnico, mas nao existem tabelas master `pdv_lojas/pdv_usuarios/pdv_meios_pagamento`. |

---

## 4) Checklist v3.0 da documentacao - status real

### 4.1 Migrations

| Item | Status | Observacao |
|---|---|---|
| Colunas novas em `pdv_turnos` (`duracao_minutos`, `periodo`, `responsavel_*`, `qtd_vendas`, `total_vendas`, `qtd_vendedores`) | Nao implementado | Tabela atual nao possui esses campos. |
| Coluna `canal` em `pdv_vendas` | Nao implementado | Nao existe coluna; chave unica atual ignora canal. |
| Criar `pdv_vendas_resumo` | Nao implementado | Tabela inexistente. |
| Criar `pdv_lojas` | Nao implementado | Tabela inexistente. |
| Criar `pdv_usuarios` | Nao implementado | Tabela inexistente. |
| Criar `pdv_meios_pagamento` | Nao implementado | Tabela inexistente. |
| Indices de performance novos v3 | Parcial | Existem indices base v2, mas nao os indices focados em canal/snapshot/responsavel. |

### 4.2 Webhook

| Item | Status | Observacao |
|---|---|---|
| Aceitar `schema_version: 3.0` | Parcial | Configuravel por env, mas default esta em `2.0` (`.env.example:81`) e schema file so mapeia `2.0` (`config/pdv.php:26`). |
| Processar `snapshot_turnos[]` com UPSERT | Nao implementado | Nao ha fluxo no job para snapshot. |
| Processar `snapshot_vendas[]` com UPSERT | Nao implementado | Nao ha fluxo no job para snapshot. |
| Armazenar `canal` nas vendas | Nao implementado | Nao existe coluna e nao ha parse do campo no job. |
| Processar `ops.loja_count/loja_ids` | Nao implementado | Regras/request/controller nao tratam esses campos. |
| Auto-cadastro lojas/vendedores tabelas master | Nao implementado | Nao existem as tabelas master propostas. |

### 4.3 Dashboard/API

| Item | Status | Observacao |
|---|---|---|
| Endpoint de fechamento de caixa por turno (novo recorte PDV v3) | Parcial | Existem endpoints legacy de caixa, mas nao endpoint dedicado com base em `pdv_turnos` v3. |
| Endpoint de vendas com filtros por vendedor/loja/canal/periodo | Nao implementado | Nao ha endpoint PDV com filtro por `canal`. |
| Ranking diario/semanal/mensal por vendedor no contexto PDV v3 | Parcial | Ranking atual existe, mas usa tabela legacy `sales` e nao possui `canal`. |
| Dashboard de saude de sync | Implementado (base) | `GET /api/v1/admin/pdv/syncs/metrics` com backlog, risco e lojas stale. |
| Alertas de lojas silenciosas (>2h) | Parcial | Endpoint mostra stale stores; alerta automatico do monitor atual foca fila/backlog, nao silencio por loja. |

---

## 5) O que ja esta implementado e pode ser reaproveitado

1. Ingestao segura e idempotente:
- Dedupe por `sync_id` antes de qualquer processamento (`app/Http/Controllers/Api/V1/PdvSyncController.php:38`).
- Retorno `200` para duplicado.
- Persistencia curta + enfileiramento do job (`app/Http/Controllers/Api/V1/PdvSyncController.php:240`).

2. Seguranca e rastreabilidade:
- Middleware de assinatura/fallback ja implantado.
- Correlacao por `X-Request-Id` (`app/Http/Controllers/Api/V1/PdvSyncController.php:35`).
- `schema_version` e `event_type` persistidos em `pdv_syncs`.

3. Processamento assincrono robusto:
- Lock por loja para preservar ordem.
- UPSERT em lote para turnos, vendas, itens e pagamentos (`app/Jobs/ProcessPdvSyncJob.php:341`, `:550`).
- Idempotencia de filhos com `line_id` e fallback `row_hash`.

4. Operacao e observabilidade:
- Endpoint admin de listagem e metricas de sync.
- Monitor de operacao com thresholds de fila (`app/Console/Commands/PdvOpsMonitorCommand.php`).
- Comandos de retry e purge de payload bruto.

5. Cobertura de testes de ingestao:
- Testes para assinatura, idempotencia, timestamp mode e `turno_closure` (`tests/Feature/Api/V1/PdvSyncWebhookTest.php`).

---

## 6) Gaps criticos (P0) para v3.0

1. Colisao de vendas entre canais (critico):
- Sem coluna `canal`, uma venda de `HIPER_LOJA` pode sobrescrever/colidir com `HIPER_CAIXA` quando `id_operacao` coincidir.
- Evidencia: unique atual `store_pdv_id + id_operacao` (`database/migrations/2026_02_10_000150_create_pdv_vendas_table.php:25`).

2. Ausencia total de snapshot UPSERT:
- Sem `snapshot_turnos` e `snapshot_vendas`, perde-se o mecanismo de auto-correcao v3.0.

3. Schema/contrato ainda orientado a v2:
- Config default e schema formal estao em `2.0`.
- Sem schema v3 mapeado e sem testes para payload v3.

4. Campos v3 de turno nao persistidos:
- `responsavel`, `periodo`, `duracao_minutos`, `qtd_vendedores`, `qtd_vendas`, `total_vendas` nao entram no banco.

5. Sem camada de normalizacao master pedida:
- Nao existem `pdv_lojas`, `pdv_usuarios`, `pdv_meios_pagamento`.
- Auto-cadastro recomendado pelo documento ainda nao existe.

---

## 7) Melhorias recomendadas

### 7.1 P0 - obrigatorio para liberar v3

1. Evoluir schema e tabela de vendas para dual-canal:
- Adicionar coluna `canal` em `pdv_vendas`.
- Ajustar chave unica para incluir `canal` (ou campo equivalente de origem).
- Revisar chaves de `pdv_venda_itens`/`pdv_venda_pagamentos` para evitar colisao cross-canal.

2. Implementar snapshots:
- Processar `snapshot_turnos[]` com UPSERT por `store_pdv_id + id_turno`.
- Criar e processar `pdv_vendas_resumo` para `snapshot_vendas[]` com UPSERT por chave com canal.

3. Atualizar contrato de entrada:
- Aceitar explicitamente `schema_version=3.0`.
- Criar `docs/schema_v3.0.json`.
- Atualizar `config/pdv.php` e `.env.example` para versoes suportadas.

4. Persistir novos campos de turno v3:
- `responsavel_id/nome`, `duracao_minutos`, `periodo`, `qtd_vendas`, `total_vendas`, `qtd_vendedores`.

5. Tratar `ops.loja_count` e `ops.loja_ids`:
- Persistir no metadado de sync e usar para diagnostico/dedupe de loja-canal.

### 7.2 P1 - recomendacao forte

1. Criar tabelas master de normalizacao:
- `pdv_lojas`, `pdv_usuarios`, `pdv_meios_pagamento`.
- Auto-cadastro (upsert) durante ingestao.

2. Endpoints de consulta PDV v3:
- Fechamento por turno com filtros de loja/data/turno/periodo.
- Vendas por vendedor com filtro de `canal`.
- Ranking diario/semanal/mensal usando `pdv_*`, nao so `sales`.

3. Monitoramento de loja silenciosa com alerta ativo:
- Hoje ha visao stale por endpoint.
- Falta alerta automatico focado em ultima sincronizacao por loja (>2h).

4. Cobertura de testes de processamento:
- Hoje os testes focam ingestao.
- Faltam testes de `ProcessPdvSyncJob` validando gravacao real de turnos/vendas/snapshots/canal.

### 7.3 P2 - melhorias estruturais

1. Regras mais estritas de `event_type`:
- Hoje valores desconhecidos caem para `sales` com risk flag.
- Para v3, considerar erro de contrato em modo estrito.

2. Deteccao/anomalia por `integrity.warnings[]`:
- Persistencia existe, mas falta pipeline de analise/alerta especifico.

3. Hardening de desempenho para v3:
- Novos indices para filtros por `canal`, `periodo`, `responsavel_id`, snapshots.

---

## 8) Plano de execucao sugerido

### Fase 1 (1-2 dias) - Banco + contrato v3

1. Migrations v3:
- `pdv_turnos`: novos campos.
- `pdv_vendas`: `canal` + ajuste de indice/chave.
- nova tabela `pdv_vendas_resumo`.
- tabelas master (`pdv_lojas`, `pdv_usuarios`, `pdv_meios_pagamento`).

2. Config/schema:
- novo schema `3.0`.
- suporte em `config/pdv.php` + `.env.example`.

### Fase 2 (2-3 dias) - Ingestao e processamento

1. Atualizar `PdvSyncIngestRequest` para campos v3.
2. Atualizar `ProcessPdvSyncJob`:
- `vendas[].canal`
- `turnos[].responsavel`
- `snapshot_turnos[]`
- `snapshot_vendas[]`
- `ops.loja_count/loja_ids`
3. Auto-cadastro nas tabelas master.

### Fase 3 (1-2 dias) - API de consumo + monitoramento

1. Endpoints novos de consulta PDV v3.
2. Alerta automatico de loja silenciosa >2h.
3. Ajustes de dashboard de saude por loja/hora.

### Fase 4 (1-2 dias) - Testes e go-live

1. Testes feature/unit para contrato v3.
2. Testes de regressao v2.
3. Carga de smoke em homologacao.

---

## 9) Criterio de pronto para v3.0

Considerar pronto quando:
- Payload v3.0 completo entra sem perda de campo.
- `canal` esta persistido e usado na chave correta de dedupe.
- snapshots estao ativos com UPSERT de auto-correcao.
- tabelas master de normalizacao estao populadas via auto-cadastro.
- endpoint de saude alerta loja silenciosa >2h automaticamente.
- testes cobrem ingestao + processamento v3 fim-a-fim.

---

## 10) Conclusao

O backend atual esta bem estruturado para v2 e fornece base forte de operacao.  
Para v3.0, o principal trabalho restante e de **evolucao de modelo de dados e processamento** (dual-canal + snapshots + normalizacao).  
Sem essas evolucoes, existe risco real de inconsistencias de venda e perda de capacidade de autocorrecao prometida pelo agente v3.0.

