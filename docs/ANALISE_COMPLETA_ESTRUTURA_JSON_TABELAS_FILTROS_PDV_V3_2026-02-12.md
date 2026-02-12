# ANALISE COMPLETA - ESTRUTURA TABELAS, RELACIONAMENTOS E JSON PDV v3

Data: 2026-02-12  
Projeto: `maiscapinhas-erp-api`  
Escopo: webhook PDV v3 (`/api/v1/pdv/sync`) + consultas de relatorio (`/api/v1/pdv/reports/*`)

---

## 1) Fontes analisadas

Codigo:
- `app/Http/Controllers/Api/V1/PdvSyncController.php`
- `app/Http/Requests/Pdv/PdvSyncIngestRequest.php`
- `app/Jobs/ProcessPdvSyncJob.php`
- `app/Http/Controllers/Api/V1/PdvReportsController.php`
- `config/pdv.php`
- `docs/schema_v3.0.json`
- migrations `pdv_*` em `database/migrations/*pdv*`

JSON bruto real capturado no n8n:
- `C:\Users\Usuario\Desktop\dados\1.json`
- `C:\Users\Usuario\Desktop\dados\2.json`
- `C:\Users\Usuario\Desktop\dados\3.json`
- `C:\Users\Usuario\Desktop\dados\4.json`
- `C:\Users\Usuario\Desktop\dados\5.json`
- `C:\Users\Usuario\Desktop\dados\6.json`

Testes executados nesta analise:
- `tests/Unit/Jobs/ProcessPdvSyncJobFixtureFilesTest.php`: passou
- `tests/Feature/Api/V1/PdvReportsControllerTest.php` e `tests/Feature/Api/V1/PdvSyncWebhookTest.php`: bloqueados por acesso negado ao DB de teste remoto (`maiscapinhas_erp_test`)

---

## 2) Visao geral da arquitetura atual

Hoje existem **2 dominios de caixa** no backend:

1. Dominio PDV webhook (`pdv_*`)
- Recebe JSON do agente PDV v3
- Persiste turnos, vendas, itens, pagamentos, snapshots e metadados de sync
- E o dominio usado para os endpoints `GET /api/v1/pdv/reports/*`

2. Dominio interno legado/operacional (`cash_*`)
- `cash_shifts`, `cash_closings`, `cash_closing_lines`
- Fluxo de fechamento manual com aprovacao/rejeicao
- Nao e o mesmo pipeline do webhook PDV

Conclusao importante:
- O que chega no webhook v3 alimenta `pdv_*`.
- O modulo `cash_*` e outro fluxo. Se o front misturar os dois sem regra clara, gera divergencia de entendimento.

---

## 3) Estrutura de tabelas PDV e relacionamentos

## 3.1 Tabelas de ingestao e controle

- `pdv_syncs`
  - PK: `id`
  - Unico: `sync_id`
  - Campos chave: `schema_version`, `event_type`, `store_pdv_id`, `store_id`, `ops_count`, `ops_loja_count`, `ops_loja_ids`, `snapshot_turnos_count`, `snapshot_vendas_count`, `status`, `risk_flags`, datas de processamento

- `pdv_sync_payloads`
  - 1:1 com `pdv_syncs` por `pdv_sync_id` (unico)
  - Guarda RAW payload

- `pdv_store_mappings`
  - Mapeia `pdv_store_id` -> `stores.id`

- `pdv_user_mappings`
  - Mapeia `store_pdv_id + pdv_user_id` -> `users.id`

## 3.2 Tabelas de negocio PDV

- `pdv_turnos`
  - Chave unica: `(store_pdv_id, id_turno)`
  - Campos v3: `duracao_minutos`, `periodo`, `responsavel_pdv_id`, `responsavel_nome`, `qtd_vendas`, `total_vendas`, `qtd_vendedores`
  - Fechamento: `total_sistema`, `total_declarado`, `total_falta`

- `pdv_turno_pagamentos`
  - Chave unica: `(store_pdv_id, id_turno, tipo, id_finalizador)`
  - `tipo` = `sistema` | `declarado` | `falta`

- `pdv_vendas`
  - Chave canonica v3: `(store_pdv_id, canal, id_operacao)`
  - `canal`: `HIPER_CAIXA` | `HIPER_LOJA`

- `pdv_venda_itens`
  - Chaves atuais: `(store_pdv_id, line_id)` e `(store_pdv_id, id_operacao, row_hash)`
  - **Nao possui coluna `canal` (gap critico)**

- `pdv_venda_pagamentos`
  - Chaves atuais: `(store_pdv_id, line_id)` e `(store_pdv_id, id_operacao, row_hash)`
  - **Nao possui coluna `canal` (gap critico)**

- `pdv_vendas_resumo` (snapshot vendas)
  - Chave: `(store_pdv_id, canal, id_operacao)`

## 3.3 Tabelas master/normalizacao

- `pdv_lojas` (`id_ponto_venda` unico)
- `pdv_usuarios` (`id_usuario_hiper` unico)
- `pdv_meios_pagamento` (`id_finalizador` unico)

Auto-cadastro/upsert de master data ocorre no `ProcessPdvSyncJob`.

---

## 4) Analise do JSON bruto que chega

## 4.1 Contrato oficial aceito (v3 only)

Em codigo (`config/pdv.php`):
- `supported_schema_versions = ['3.0']`
- `json_schema_files['3.0'] = docs/schema_v3.0.json`

No schema (`docs/schema_v3.0.json`):
- `schema_version` fixo em `3.0`
- `event_type` permitido: `sales`, `turno_closure`, `mixed`
- Estrutura raiz esperada inclui:
  - `agent`, `store`, `window`, `turnos`, `vendas`, `resumo`, `snapshot_turnos`, `snapshot_vendas`, `ops`, `integrity`

## 4.2 O que foi encontrado nos 6 JSON reais (pasta `C:\Users\Usuario\Desktop\dados`)

Esses arquivos estao no formato de captura do n8n:
- raiz com `headers`, `params`, `query`, `body`, `webhookUrl`, `executionMode`
- payload PDV real esta dentro de `body`

Resumo encontrado no `body`:
- `schema_version = 3.0` em todos
- `event_type = sales` em todos
- `vendas` presentes (1 por payload)
- `snapshot_turnos = 10` e `snapshot_vendas = 10` em todos
- `canal` observado: `HIPER_CAIXA`

Achado importante de compatibilidade:
- no envelope do n8n, header `x-pdv-schema-version` veio como `2.0`, enquanto o body estava `3.0`.
- se reenviar esse envelope "como esta" para o endpoint, o backend pode retornar `422` por mismatch header/payload.

---

## 5) Mapeamento JSON -> banco (implementacao atual)

## 5.1 Ingestao

- `integrity.sync_id` -> idempotencia em `pdv_syncs.sync_id`
- metadados (`schema_version`, `event_type`, `ops.*`, snapshots count, warnings, risk flags) -> `pdv_syncs`
- payload raw -> `pdv_sync_payloads`

## 5.2 Turnos

- `turnos[]` -> upsert em `pdv_turnos` por `(store_pdv_id, id_turno)`
- `turnos[].totais_sistema/fechamento_declarado/falta_caixa.por_pagamento[]`
  -> upsert em `pdv_turno_pagamentos`

## 5.3 Vendas

- `vendas[]` -> upsert em `pdv_vendas` por `(store_pdv_id, canal, id_operacao)`
- `vendas[].itens[]` -> `pdv_venda_itens` (line_id / row_hash)
- `vendas[].pagamentos[]` -> `pdv_venda_pagamentos` (line_id / row_hash)

## 5.4 Snapshots

- `snapshot_turnos[]` -> upsert em `pdv_turnos` (auto-correcao)
- `snapshot_vendas[]` -> upsert em `pdv_vendas_resumo`

## 5.5 Master data

- `store` -> `pdv_lojas`
- operadores/responsaveis/vendedores -> `pdv_usuarios`
- meios de pagamento observados -> `pdv_meios_pagamento`

---

## 6) Resposta objetiva das perguntas de negocio

## 6.1 "Conseguimos filtrar turno por loja, data, turno, vendedor (quem fechou) e aberto/fechado?"

Status atual:
- Loja: **SIM** (`store_id` ou `store_pdv_id`)
- Data: **SIM** (`date`)
- Turno: **SIM** (`sequencial`) e periodo (`periodo`)
- Vendedor que fechou (responsavel): **PARCIAL**
  - dado existe (`responsavel_pdv_id`, `responsavel_nome`)
  - filtro por responsavel ainda nao existe no endpoint
- Aberto/fechado: **PARCIAL**
  - campo existe e retorna (`fechado`, `status`)
  - filtro `fechado=true/false` ainda nao existe no endpoint

## 6.2 "No filtro de turno temos total por meio de pagamento?"

- **SIM** no endpoint `GET /api/v1/pdv/reports/turnos`
- Retorna por `tipo`:
  - `pagamentos.sistema[]`
  - `pagamentos.declarado[]`
  - `pagamentos.falta[]`

Isso atende diretamente o caso "comparar sistema x envelope" por turno e por meio.

## 6.3 "Conseguimos listar vendas por periodo de vendedor, loja ou vendedor x loja?"

Status atual:
- Vendas por periodo + loja: **SIM** (`/pdv/reports/vendas` com `from/to` + `store_id`)
- Vendas por periodo + vendedor: **SIM** (`/pdv/reports/vendas` com `vendedor_id`)
- Vendedor x loja por periodo: **SIM** (combinando `store_id` + `vendedor_id`)
- Ranking agregado por vendedor no periodo: **SIM** (`/pdv/reports/ranking-vendedores`)
- Ranking cruzado "vendedor x loja" em uma unica consulta multi-loja: **NAO** (nao ha endpoint pronto para breakdown por loja)

## 6.4 Operador x Vendedor x Responsavel (turno)

Implementacao atual:
- `operador` = quem opera/abre turno
- `vendedor` = quem vende nos itens (`pdv_venda_itens.vendedor_*`)
- `responsavel` no turno = vendedor principal do turno (regra do agente: maior qtd de itens), podendo ser `null`

A sua regra de negocio esta refletida no schema/tabela: `responsavel_pdv_id/nome`.

---

## 7) GAP CRITICO (P0): canal nas tabelas filhas de venda

## Problema

`pdv_vendas` ja e dual-channel e usa chave canonica correta:
- `(store_pdv_id, canal, id_operacao)`

Mas `pdv_venda_itens` e `pdv_venda_pagamentos` nao possuem `canal`.

Nos relatorios atuais, joins sao feitos por:
- `store_pdv_id + id_operacao`

Isso pode misturar dados quando existir colisao de `id_operacao` entre `HIPER_CAIXA` e `HIPER_LOJA`.

## Impacto

- `/pdv/reports/vendas`: agregados de itens/pagamentos podem vir incorretos em colisoes
- `/pdv/reports/ranking-vendedores`: pode contar item no canal errado e/ou duplicar agregacao
- Filtros por `canal` ficam semanticamente inseguros em cenarios de colisao

## Recomendacao P0

1. Adicionar `canal` em:
- `pdv_venda_itens`
- `pdv_venda_pagamentos`

2. Atualizar chaves unicas:
- itens: `(store_pdv_id, canal, line_id)` e fallback `(store_pdv_id, canal, id_operacao, row_hash)`
- pagamentos: `(store_pdv_id, canal, line_id)` e fallback `(store_pdv_id, canal, id_operacao, row_hash)`

3. Atualizar `ProcessPdvSyncJob` para propagar `canal` da venda para item/pagamento

4. Atualizar queries de relatorio para join por:
- `store_pdv_id + canal + id_operacao`

5. Criar teste com colisao real:
- mesmo `store_pdv_id` + mesmo `id_operacao` em ambos canais
- validar que agregacao por canal nao mistura

---

## 8) Outros gaps e melhorias recomendadas

## P1 - Filtros de turno (funcional)

Adicionar em `GET /pdv/reports/turnos`:
- `fechado` (`true|false`)
- `responsavel_id` (vendedor principal do turno)
- `operador_id`
- opcional: faixa de data (`from/to`) alem de `date`

## P1 - Filtro de vendas por meio de pagamento

Adicionar em `GET /pdv/reports/vendas`:
- `id_finalizador`
- `meio_pagamento`

Implementacao via `whereExists` em `pdv_venda_pagamentos`.

## P1 - Consulta vendedor x loja (grade)

Criar endpoint de agregacao cruzada:
- exemplo: `/api/v1/pdv/reports/vendedor-loja`
- `group by store_id, vendedor_id` por periodo

## P2 - Governanca de dados

- Definir oficialmente qual dominio o front usa para fechamento:
  - `pdv_*` (webhook) ou `cash_*` (manual)
- Se coexistirem, documentar regra de precedencia para evitar numeros diferentes na tela.

---

## 9) Estrutura atual comporta os filtros inteligentes?

Resposta curta: **comporta bem, mas ainda nao 100%**.

- Base de dados: **quase pronta** para loja/turno/vendedor/periodo/meio.
- API atual: cobre bastante, mas faltam filtros especificos (responsavel/fechado/meio).
- Precisao por canal: **tem risco real** enquanto itens/pagamentos nao tiverem `canal`.

Sem corrigir o GAP P0, o relatorio pode ficar inconsistente exatamente nos cenarios dual-db que a v3 trouxe.

---

## 10) Checklist pratico (proximo ciclo)

1. Corrigir modelagem por canal em itens/pagamentos (P0)
2. Ajustar joins dos relatorios para usar canal (P0)
3. Adicionar filtros `fechado` e `responsavel_id` em turnos (P1)
4. Adicionar filtro por meio de pagamento em vendas (P1)
5. Criar endpoint agregado `vendedor x loja` (P1)
6. Executar suite de testes PDV em ambiente com DB de teste funcional

---

## 11) Evidencias no codigo (arquivo:linha)

- Versao suportada v3-only:
  - `config/pdv.php:23`
  - `config/pdv.php:26`

- Validacao de schema header x payload:
  - `app/Http/Controllers/Api/V1/PdvSyncController.php:96`
  - `app/Http/Controllers/Api/V1/PdvSyncController.php:104`

- Persistencia de `ops_loja_*` e snapshots count em sync:
  - `app/Http/Controllers/Api/V1/PdvSyncController.php:292`
  - `app/Http/Controllers/Api/V1/PdvSyncController.php:296`

- Upsert canonico de venda com `canal`:
  - `app/Jobs/ProcessPdvSyncJob.php:596`
  - `app/Jobs/ProcessPdvSyncJob.php:600`

- Upsert de itens/pagamentos sem `canal` (ponto critico):
  - `app/Jobs/ProcessPdvSyncJob.php:640`
  - `app/Jobs/ProcessPdvSyncJob.php:650`
  - `app/Jobs/ProcessPdvSyncJob.php:654`
  - `app/Jobs/ProcessPdvSyncJob.php:676`

- Snapshot turnos/vendas:
  - `app/Jobs/ProcessPdvSyncJob.php:785`
  - `app/Jobs/ProcessPdvSyncJob.php:694`

- Filtros atuais de `turnos`:
  - `app/Http/Controllers/Api/V1/PdvReportsController.php:25`
  - `app/Http/Controllers/Api/V1/PdvReportsController.php:31`
  - `app/Http/Controllers/Api/V1/PdvReportsController.php:70`
  - `app/Http/Controllers/Api/V1/PdvReportsController.php:74`

- Retorno de totais por meio no turno:
  - `app/Http/Controllers/Api/V1/PdvReportsController.php:82`
  - `app/Http/Controllers/Api/V1/PdvReportsController.php:118`
  - `app/Http/Controllers/Api/V1/PdvReportsController.php:509`

- Join de vendas/itens/pagamentos sem `canal`:
  - `app/Http/Controllers/Api/V1/PdvReportsController.php:172`
  - `app/Http/Controllers/Api/V1/PdvReportsController.php:189`
  - `app/Http/Controllers/Api/V1/PdvReportsController.php:192`
  - `app/Http/Controllers/Api/V1/PdvReportsController.php:199`

- Ranking vendedores com join sem `canal`:
  - `app/Http/Controllers/Api/V1/PdvReportsController.php:309`
  - `app/Http/Controllers/Api/V1/PdvReportsController.php:313`

- Rotas publicas/protegidas PDV:
  - `routes/api_v1.php:62`
  - `routes/api_v1.php:233`
  - `routes/api_v1.php:237`
