# Revisao Critica dos PR Tasks - PDV Sync v3

Data: 2026-02-12  
Projeto: `maiscapinhas-erp-api`  
Base: validacao no codigo atual + testes locais

---

## 1) Veredito rapido

Status geral do plano enviado: **bom**, mas com pontos desatualizados.

- Correto e **prioridade P0 real**: PR #1 (canal nas tabelas filhas + chaves/joins).
- Parcialmente correto: PR #2 (fix no agente sim; ajuste de config backend nao, pois backend ja esta v3-only).
- Majoritariamente ja implementado: PR #3 (auto-populate de tabelas dimensao).
- Parcial/opcional: PR #4 (regra de exibicao de falta/sobra depende de frontend/API response contract).
- Futuro/opcional: PR #5 (tracking de cancelamento por snapshot).
- Fora deste repositorio: PR #6 (agente).

---

## 2) O que esta confirmado no codigo (e nao precisa repetir)

1. Backend v3-only hardcoded:
- `config/pdv.php:23` -> `supported_schema_versions => ['3.0']`
- `config/pdv.php:25-27` -> schema file v3.0
- `app/Http/Controllers/Api/V1/PdvSyncController.php:81`
- `app/Http/Requests/Pdv/PdvSyncIngestRequest.php:22`

2. Venda pai ja com chave canonica por canal:
- `app/Jobs/ProcessPdvSyncJob.php:596-601` usa `['store_pdv_id', 'canal', 'id_operacao']`.

3. Snapshots v3 ja implementados:
- `processSnapshotVendas()` em `app/Jobs/ProcessPdvSyncJob.php:694+`
- `processSnapshotTurnos()` em `app/Jobs/ProcessPdvSyncJob.php:785+`

4. Auto-populate de dimensoes ja implementado:
- `processMasterData()` em `app/Jobs/ProcessPdvSyncJob.php:1080+`
- `pdv_lojas`, `pdv_usuarios`, `pdv_meios_pagamento`.

---

## 3) Gap critico real (P0) que ainda falta

## 3.1 Filhas sem `canal` + upsert sem `canal`

Em `ProcessPdvSyncJob`:
- item row sem `canal`: `app/Jobs/ProcessPdvSyncJob.php:504-522`
- pagamento row sem `canal`: `app/Jobs/ProcessPdvSyncJob.php:571-585`
- upsert itens por line_id: `['store_pdv_id','line_id']` em `:640-644`
- upsert itens fallback: `['store_pdv_id','id_operacao','row_hash']` em `:647-651`
- upsert pagamentos por line_id: `['store_pdv_id','line_id']` em `:654-658`
- upsert pagamentos fallback: `['store_pdv_id','id_operacao','row_hash']` em `:673-677`

Nas migrations atuais:
- `line_id` unico sem canal em filhos:
  - `database/migrations/2026_02_11_000200_add_line_id_to_pdv_venda_children_tables.php:26`
  - `database/migrations/2026_02_11_000200_add_line_id_to_pdv_venda_children_tables.php:33`

**Conclusao:** risco real de colisao cross-canal continua aberto nas tabelas filhas.

## 3.2 Relatorios ainda juntam por `store_pdv_id + id_operacao` sem `canal`

`PdvReportsController`:
- agregacao itens: `groupBy(vi.store_pdv_id, vi.id_operacao)` em `app/Http/Controllers/Api/V1/PdvReportsController.php:172-180`
- agregacao pagamentos: `groupBy(vp.store_pdv_id, vp.id_operacao)` em `:182-189`
- join subqueries com vendas sem `canal`: `:191-199`
- filtro vendedor sem `canal` no exists: `:227-233`

**Conclusao:** mesmo apos corrigir ingestao, relatorio precisa join/filter com `canal` para isolamento total.

---

## 4) Revisao por PR (do plano enviado)

## PR #1 - Canal em tabelas filhas

**Status:** MANter (P0), com pequenos ajustes.

Faz sentido:
- adicionar `canal` em `pdv_venda_itens` e `pdv_venda_pagamentos`
- incluir `canal` nas uniques de `line_id`, `row_hash`, `line_no`
- propagar `canal` no job
- trocar chaves de upsert para incluir `canal`
- adicionar testes de colisao por mesmo `line_id` em canais distintos

Ajuste necessario:
- incluir no mesmo PR ajuste de `PdvReportsController` para joins/exists com `canal`.

## PR #2 - Fix header schema version no agente

**Status:** MANTER PARCIAL (split por repositorio).

- No `pdv-sync-agent`: faz total sentido (bug de header deve ser corrigido).
- No backend deste repo: parte "incluir 3.0 no supported_schema_versions" **nao faz sentido agora**, porque ja esta v3-only hardcoded.

## PR #3 - Auto-populacao de tabelas dimensao

**Status:** JA IMPLEMENTADO em grande parte.

O que ainda faz sentido:
- reforcar testes de regressao (idempotencia + atualizacao de nome/categoria)
- revisar regras de prioridade de `papel` e `categoria` se quiser endurecer semantica

## PR #4 - Tratamento de falta_caixa negativo

**Status:** PARCIAL.

- Persistencia backend ja aceita valor negativo por tipo decimal (ok).
- O que pode virar PR pequeno: padronizar resposta de API com campo derivado (`FALTA|SOBRA|CONFERIDO`) se isso for requisito de front.

## PR #5 - last_seen_in_snapshot_at

**Status:** OPCIONAL (P2).

Faz sentido para observabilidade, mas nao bloqueia integracao v3. Pode entrar depois de fechar P0.

## PR #6 - Tiebreaker de responsavel no agente

**Status:** VALIDO, mas fora deste repo.

---

## 5) Ordem recomendada de execucao (realista)

1. **PR-A (P0)**: canal nas tabelas filhas + job + relatorios + testes de colisao cross-canal.
2. **PR-B (P1)**: filtros adicionais de relatorio (fechado/responsavel/operador/meio).
3. **PR-C (P1/P2)**: ajustes de resposta para falta/sobra e refinamentos analiticos.
4. **PR-D (P2)**: tracking `last_seen_in_snapshot_at`.
5. **Agente externo**: fix header schema + tiebreaker responsavel.

---

## 6) Resultado dos testes executados agora

Passaram:
- `tests/Unit/Jobs/ProcessPdvSyncJobCanalTest.php`
- `tests/Unit/Jobs/ProcessPdvSyncJobMasterDataTest.php`
- `tests/Unit/Jobs/ProcessPdvSyncJobFixtureFilesTest.php`

Falharam por infraestrutura de DB de teste (nao por regra de negocio):
- `tests/Feature/Api/V1/PdvReportsControllerTest.php` com erro de acesso ao banco `maiscapinhas_erp_test`.

---

## 7) Decisao objetiva

Se o objetivo e atacar o maior risco de dados agora, foque imediatamente em:

1. `canal` nas tabelas filhas (`pdv_venda_itens`/`pdv_venda_pagamentos`)  
2. joins de relatorio por `store_pdv_id + canal + id_operacao`  
3. testes de colisao por mesmo `id_operacao` e mesmo `line_id` em canais diferentes

Todo o resto pode ser faseado depois sem comprometer a integridade principal do v3.
