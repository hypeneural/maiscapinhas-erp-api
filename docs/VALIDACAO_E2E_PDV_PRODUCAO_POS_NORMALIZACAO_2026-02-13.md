# Validacao E2E PDV (Producao) - Pos Normalizacao (CNPJ/Login)

Data: 2026-02-13  
Projeto: `maiscapinhas-erp-api` (Laravel)  
Ambiente: Producao (`https://api.maiscapinhas.com.br/api/v1`)  
Escopo: webhook `/pdv/sync` + fila + normalizacao store/user + relatórios `/pdv/reports/*`

## 1) Objetivo

Validar ponta-a-ponta que:

1. o webhook PDV recebe payload `schema_version=3.0` (com campos novos `store.cnpj` e `*.login`) sem `422`;
2. o scheduler/cron está drenando a fila automaticamente (jobs saem de `queued` -> `processed`);
3. a resolucao/normalizacao vincula corretamente:
   - loja (`stores`) via `CNPJ first`;
   - usuarios (`users`) via `Login first`;
4. as tabelas operacionais recebem os dados com relacionamentos consistentes;
5. os endpoints de relatorios PDV retornam dados e os filtros funcionam.

---

## 1.1) Workflow rapido (pos-deploy)

1. Health (API viva):
   - `GET /api/v1/health` -> `200`
2. Ingestao (webhook recebe):
   - `POST /api/v1/pdv/sync` com `schema_version=3.0`, `store.cnpj`, `*.login`
   - esperado: `201 created`, `processing_status=queued`, `risk_flags=[]` (para lojas mapeadas)
3. Fila (scheduler/cron drenando):
   - em <= 2 minutos, `pdv_syncs.status` deve ir de `queued` -> `processed`
4. Persistencia (dados normalizados):
   - `pdv_vendas.store_id` preenchido (binding por CNPJ)
   - `pdv_venda_itens.vendedor_user_id` preenchido quando `vendedor.login` existe no mapping
5. Relatorios (filtros):
   - validar `GET /api/v1/pdv/reports/{turnos|vendas|ranking-*}` em sessao autenticada (SPA/cookie)

## 2) Estado Atual (Resumo)

Resultado desta validacao:

- `GET /health`: **OK**
- `POST /pdv/sync` (3.0, com `cnpj/login`): **OK** (201 + `risk_flags=[]`)
- Fila/scheduler: **OK** (syncs processados automaticamente)
- Normalizacao: **OK**
  - `store_id` resolvido por `store.cnpj` mesmo com `store.alias` errado
  - `vendedor_user_id` resolvido por `vendedor.login` mesmo com `id_usuario` divergente
- Persistencia de `login` nas tabelas operacionais: **OK**
- `GET /pdv/reports/*` (com auth Sanctum): **OK** (200 + filtros retornando resultados; usuario precisa ter acesso a pelo menos 1 loja)

---

## 3) Evidencias Objetivas (Producao)

### 3.1 Health

`GET https://api.maiscapinhas.com.br/api/v1/health` retornou `200` com `status=ok`.

### 3.2 Ingestao + Fila (E2E) - Venda controlada

Foi enviado 1 payload controlado (1 venda + 1 item + 1 pagamento + 1 turno) para a loja Mata Atlantica:

- `pdv_syncs.id=98` (`sync_id=e2e-1770945575-74260`):
  - Ingestao: `201 created`, `risk_flags=[]`
  - Status final: `processed`
  - `store_pdv_id=9` e `store_id=8` (resolvido)

Persistencia verificada:

- `pdv_vendas`: `id_operacao=900000001`, `canal=HIPER_CAIXA`, `store_id=8`, `sync_id=e2e-...`
- `pdv_venda_itens`: `vendedor_pdv_id=46`, `vendedor_login=biancabrasil`, `vendedor_user_id=19`
- `pdv_venda_pagamentos`: `id_finalizador=1`, `meio_pagamento=Dinheiro`, `valor=10.00`
- `pdv_turnos`: `id_turno=4EAC6F06-...`, `operador_login=mataatlantica`, `responsavel_login=biancabrasil`
- `pdv_turno_pagamentos`: tipo `sistema`, `Dinheiro` total 10.00

### 3.2.1 Ingestao + Fila (E2E) - Venda controlada (execucao mais recente)

Para validar o estado **apos o ultimo deploy**, foi enviado mais 1 payload controlado (venda + item + pagamento) em producao:

- `pdv_syncs.id=110` (`sync_id=e2e-prod-924144626-20260213023538`):
  - Ingestao: `201 created`, `risk_flags=[]`
  - Status final: `processed` (em ~1 minuto)
  - `store_pdv_id=9` e `store_id=8` (resolvido por CNPJ)

Persistencia verificada:

- `pdv_vendas`: `id_operacao=900000102`, `canal=HIPER_CAIXA`, `store_id=8`, `sync_id=e2e-prod-...`
- `pdv_venda_itens`: `vendedor_pdv_id=41`, `vendedor_login=biancabrasil`, `vendedor_user_id=19`
- `pdv_venda_pagamentos`: `id_finalizador=4`, `meio_pagamento=Cartao de credito`, `valor=22.50`

### 3.2.2 Ingestao + Fila (E2E) - Execucao Codex (2026-02-13 13:48 UTC)

Payload controlado enviado diretamente para o endpoint (sem n8n), para validar:
- parse do JSON
- resolucao de loja por `store.cnpj`
- resolucao de usuario por `vendedor.login`
- consumo automatico da fila (worker + cron)
- persistencia em `pdv_vendas`/`pdv_venda_itens`/`pdv_venda_pagamentos`

**Request**
- Endpoint: `POST /api/v1/pdv/sync`
- Headers:
  - `Content-Type: application/json`
  - `Accept: application/json`
  - `X-PDV-Schema-Version: 3.0`

**Response**
- `201 created`
- `pdv_sync_id=201`
- `risk_flags=[]`

**Fila**
- `pdv_syncs.id=201`:
  - `status`: `queued` -> `processed`
  - `processing_started_at`: `2026-02-13 13:49:02` UTC
  - `processed_at`: `2026-02-13 13:49:02` UTC

**Persistencia**
- `pdv_vendas`: `store_id=8`, `store_pdv_id=9`, `canal=HIPER_CAIXA`, `id_operacao=99900001`, `total=10.00`
- `pdv_venda_itens`: `line_id=9990000101`, `vendedor_pdv_id=46`, `vendedor_login=biancabrasil`, `vendedor_user_id=19`
- `pdv_venda_pagamentos`: `line_id=9990000201`, `id_finalizador=1`, `meio_pagamento=Dinheiro`, `valor=10.00`, `troco=0.00`

### 3.3 Turno aberto com `duracao_minutos=null` (caso que dava 422 no n8n)

Foi enviado payload com turno aberto (`data_hora_termino=null`) e `duracao_minutos=null`:

- `pdv_syncs.id=101` (`sync_id=e2e-open-turno-1770946525-13172`):
  - Ingestao: `201 created`, **sem** `422`
  - Status final: `processed`
  - `store_id=8` resolvido por CNPJ mesmo com `store.alias` propositalmente errado

Persistencia verificada em `pdv_turnos`:

- `id_turno=DFAC5B35-...`
- `fechado=false`
- `duracao_minutos=NULL`

### 3.4 Snapshot de vendas com `vendedor.login` (persistencia em `pdv_vendas_resumo`)

Foi enviado payload com `snapshot_vendas[]` (sem `vendas[]`), contendo vendedor com `login`:

- `pdv_syncs.id=102` (`sync_id=e2e-snapshot-vendas-1770946616-97841`): `processed`
- `pdv_vendas_resumo` criado/atualizado:
  - `id_operacao=900000002`
  - `store_id=8`
  - `vendedor_pdv_id=46`
  - `vendedor_login=biancabrasil`

### 3.5 Contrato 3.1 (futuro) - Aceitacao e mismatch

Mesmo com o agente mantendo `schema_version=3.0`, validamos que o backend aceita `3.1`:

- Payload `schema_version=3.1` + header `X-PDV-Schema-Version=3.1`:
  - `pdv_syncs.id=103`: `processed`, `risk_flags=[]`
- Mismatch header/body:
  - `schema_version=3.1` + header `3.0` retorna `422` com mensagem explicita:
    - `"Schema version header does not match payload."`

---

## 4) Normalizacao: Como o Backend Faz o Binding (Lojas e Usuarios)

### 4.1 Store (loja) - `CNPJ first`

Arquivos:
- `app/Support/Pdv/PdvStoreResolver.php`
- `app/Http/Controllers/Api/V1/PdvSyncController.php`
- `app/Jobs/ProcessPdvSyncJob.php`

Ordem de resolucao:

1. `store.cnpj` (se presente) -> procura em `pdv_store_mappings.cnpj`
2. `store.id_ponto_venda + store.alias` (case-insensitive)
3. `store.id_ponto_venda + store.nome` (case-insensitive)
4. fallback por `store.id_ponto_venda` somente se existir 1 candidato ativo
5. se houver >1 candidato e sem chave forte: `store_mapping_ambiguous`

Efeitos:
- `pdv_syncs.store_id` é preenchido no **ingress** (controller) e reforçado no **job** (re-resolve se vier null).

### 4.2 User (vendedor/operador) - `Login first`

Arquivos:
- `app/Support/Pdv/PdvUserResolver.php`
- `app/Jobs/ProcessPdvSyncJob.php`

Ordem de resolucao:

1. `*.login` (case-insensitive) -> indice `pdv_user_mappings.pdv_user_login`
2. fallback para `id_usuario` -> indice `pdv_user_mappings.pdv_user_id`

Regras:
- se `is_store_operator=1`, o job retorna `user_id=NULL` (operador generico) e isso **nao** deve contaminar ranking de vendedor.
- se nao resolver: adiciona risk flags do tipo `user_mapping_missing` / `user_login_missing` (quando aplicavel).

Persistencia:
- `pdv_venda_itens.vendedor_user_id` recebe o `users.id` quando resolvido.
- logins sao persistidos em:
  - `pdv_turnos.operador_login` / `pdv_turnos.responsavel_login`
  - `pdv_venda_itens.vendedor_login`
  - `pdv_vendas_resumo.vendedor_login`

---

## 5) Validacao dos Endpoints de Relatorios (Producao)

Observacao: os endpoints de relatorio exigem `auth:sanctum`.

Requisitos validados em producao:
- Enviar `Accept: application/json` (sem isso o servidor pode responder HTML em alguns cenarios).
- Enviar `Authorization: Bearer <token>` (Sanctum).
- O usuario autenticado precisa ter acesso a pelo menos 1 loja (tabela `store_users`), senao retorna `403 Usuario sem acesso a lojas.`.

Endpoints validados (200):

- `GET /api/v1/pdv/reports/turnos`
  - `store_id=8&date=2026-02-13`
  - retornou turnos (incluindo turno E2E)
- `GET /api/v1/pdv/reports/vendas`
  - `store_id=8&from=2026-02-13&to=2026-02-13`
  - retornou vendas (incluindo venda E2E)
- Filtros em vendas (todos retornaram 1):
  - `vendedor_id=46` (**vendedor_id = vendedor_pdv_id**, id do Hiper/PDV; nao e `users.id`)
  - `id_finalizador=1&meio_pagamento=Dinheiro`
  - `canal=HIPER_CAIXA`
  - `id_turno=4EAC6F06-...`
- `GET /api/v1/pdv/reports/vendas/detalhe`
  - `store_id=8&canal=HIPER_CAIXA&id_operacao=99900001`
  - retornou itens + pagamentos + summary (extrato detalhado)
- `GET /api/v1/pdv/reports/ranking-vendedores`
  - retornou Bianca Brasil como #1 (periodo 2026-02-13)
- `GET /api/v1/pdv/reports/ranking-vendedor-loja`
  - retornou Bianca Brasil na loja Mata Atlantica (store_id=8)

---

## 6) Anomalias Encontradas (e como tratar)

### 6.1 Syncs antigos com `failed` (pre-fix)

Os `pdv_syncs.id=90..94` ficaram `failed` com erro:

`Unknown column 'is_store_operator'` (schema do banco estava atras do codigo).

Interpretacao:
- Sao artefatos do periodo **antes** da migration de normalizacao (coluna nao existia).
- Nao indicam falha atual do pipeline.

Acao sugerida:
- (Opcao A) Reprocessar via servidor (PHP com redis habilitado): `queue:retry` / `pdv:queue-consume`
- (Opcao B) Manter como historico e ignorar em metricas (filtrar por `received_at` >= fix).

### 6.2 `422` no n8n para `duracao_minutos`

Backend aceita `turnos.*.duracao_minutos = null` (turno aberto).

Se ainda ocorrer `422` no n8n:
- verificar se o n8n esta enviando `\"null\"` (string) ou `\"\"` (string vazia) ao inves de `null` JSON.
- validar tambem se o HTTP node esta enviando o body como **objeto JSON puro** (nao como wrapper do n8n tipo `[{\"body\": {...}}]`).
- se estiver testando via arquivo/script (fora do n8n), garantir **UTF-8 sem BOM** (BOM faz o Laravel nao parsear JSON e retorna "campos obrigatorios ausentes").

---

## 7) Queries Operacionais (Suporte)

### 7.1 Ultimos syncs e status

```sql
SELECT id, status, store_pdv_id, store_id, risk_flags, last_error, received_at, processed_at
FROM pdv_syncs
ORDER BY id DESC
LIMIT 30;
```

### 7.2 Backlog (fila)

```sql
SELECT status, COUNT(*) AS total
FROM pdv_syncs
WHERE received_at > NOW() - INTERVAL 2 HOUR
GROUP BY status;
```

### 7.3 Ultimas vendas por loja (normalizadas)

```sql
SELECT id, store_id, store_pdv_id, canal, id_operacao, id_turno, data_hora, total
FROM pdv_vendas
WHERE store_id IS NOT NULL
ORDER BY id DESC
LIMIT 50;
```

### 7.4 Itens com binding de vendedor (users.id)

```sql
SELECT id_operacao, vendedor_pdv_id, vendedor_login, vendedor_user_id, total
FROM pdv_venda_itens
WHERE vendedor_pdv_id IS NOT NULL
ORDER BY id DESC
LIMIT 50;
```

---

## 8) Conclusao

O pipeline PDV esta **funcional de ponta a ponta** em producao para o contrato atual do agente:

- ingestao OK
- fila/scheduler OK
- normalizacao loja/usuario OK (CNPJ/Login)
- persistencia OK
- relatorios OK (com filtros)

Pendencia recomendada:
- tratar/reprocessar os syncs `failed` antigos (90..94) para limpar ruido operacional.
- documentar no Scribe que `vendedor_id` nos filtros significa `vendedor_pdv_id` (id do Hiper/PDV), nao `users.id`.
