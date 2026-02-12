# Decisoes e Plano Pos-Respostas do Time JSON PDV v3

Data: 2026-02-12  
Projeto: `maiscapinhas-erp-api`  
Base: respostas do Time Integracao PDV (2026-02-12) + validacao no codigo backend atual

---

## 1) Resumo executivo

As respostas do time JSON confirmam o principal risco tecnico ja identificado:

1. `id_operacao` pode colidir entre `HIPER_CAIXA` e `HIPER_LOJA` (ja tratado no pai `pdv_vendas`).  
2. `line_id` de itens/pagamentos tambem pode colidir entre canais (ainda **nao** tratado nas tabelas filhas).  
3. Header `X-PDV-Schema-Version` estava hardcoded como `2.0` no agente (bug no sender).  

Conclusao:
- O backend esta correto em `pdv_vendas` (chave com `canal`), mas ainda precisa fechar P0 nas tabelas filhas e nos joins de relatorio.
- O contrato deve operar como v3-only, com plano de transicao curto para o bug de header do agente.

---

## 2) O que foi confirmado (decisao fechada)

## 2.1 Chaves canonicas

- Venda: `store_pdv_id + canal + id_operacao` (confirmado).
- Snapshot venda resumo: mesma chave canonica (confirmado).
- Turno: `store_pdv_id + id_turno` (estavel, UUID imutavel).

## 2.2 Semantica de negocio

- `responsavel` = vendedor com maior quantidade de itens no turno (pode ser `null`).
- `falta_caixa.total` pode ser negativo (sobra).
- `id_turno` em `HIPER_LOJA` pode vir preenchido ou `null` dependendo do cenario.
- Snapshot prevalece como fonte mais atual para reconciliacao.

## 2.3 Operacao

- Backlog pode chegar em janela unica grande (nao ha split automatico).
- Cancelamento explicito ainda nao existe (evento dedicado fica para v3.1).

---

## 3) Pontos que exigem acao imediata no backend (P0)

## P0.1 - Canal nas tabelas filhas

Problema:
- `pdv_venda_itens` e `pdv_venda_pagamentos` ainda sem `canal`.
- Upsert/joins por `store_pdv_id + id_operacao` podem misturar linhas de canais diferentes.

Acao obrigatoria:
1. Adicionar coluna `canal` em `pdv_venda_itens`.
2. Adicionar coluna `canal` em `pdv_venda_pagamentos`.
3. Popular `canal` no `ProcessPdvSyncJob` para cada item/pagamento.
4. Alterar chaves de upsert para incluir `canal`.
5. Ajustar queries de relatorio para join por `store_pdv_id + canal + id_operacao`.

## P0.2 - Contrato de schema header durante transicao

Fato:
- Payloads reais capturados no n8n vieram com body `3.0` e header `2.0`.

Acao recomendada:
1. Manter backend v3-only (ja esta hardcoded em `config/pdv.php`).
2. Garantir que agente seja corrigido para enviar header `3.0`.
3. Durante janela curta de transicao, monitorar `422` por mismatch header/body.
4. Nao reabrir suporte funcional a `2.0` no backend.

---

## 4) Ajustes de prioridade alta (P1)

1. Filtros em turnos:
- adicionar `fechado`, `responsavel_id`, `operador_id` em `GET /api/v1/pdv/reports/turnos`.

2. Filtros em vendas:
- adicionar `id_finalizador` e/ou `meio_pagamento` em `GET /api/v1/pdv/reports/vendas`.

3. Ranking cruzado:
- novo endpoint agregado `vendedor x loja` por periodo.

4. Empate de `responsavel`:
- documentar que hoje depende da ordenacao do SQL do agente; backend nao deve recalcular diferente sem regra fechada.

---

## 5) Divergencias/observacoes de alinhamento

Estas observacoes devem ser checadas em call para evitar ruido:

1. A recomendacao \"incluir `3.0` em `supported_schema_versions`\" ja foi superada no backend atual:
- hoje esta v3-only hardcoded.

2. Em um trecho das respostas, foi citada acao \"adicionar `canal` em `pdv_vendas`\":
- isso ja esta implementado no backend.
- gap real esta nas tabelas filhas e queries de relatorio.

3. \"Paradoxo\" de validacao header/body:
- no backend atual, mismatch deve retornar `422`.
- se algum ambiente aceitou com mismatch, revisar deploy real (codigo/cache/config em producao).

---

## 6) Plano de PR (objetivo e ordem)

## PR-A (P0) - Dual-channel consistente nas linhas

Objetivo:
- impedir mistura de itens/pagamentos entre canais.

Escopo:
1. Migration:
- `pdv_venda_itens.canal` (`VARCHAR(20)`, default `HIPER_CAIXA`, index).
- `pdv_venda_pagamentos.canal` (`VARCHAR(20)`, default `HIPER_CAIXA`, index).
- novas uniques com `canal`:
  - itens: `(store_pdv_id, canal, line_id)` e `(store_pdv_id, canal, id_operacao, row_hash)`.
  - pagamentos: `(store_pdv_id, canal, line_id)` e `(store_pdv_id, canal, id_operacao, row_hash)`.

2. Job:
- propagar `canal` da venda para item/pagamento.
- atualizar chaves de `upsertRows`.

3. Relatorios:
- joins de agregacao por `store_pdv_id + canal + id_operacao`.

4. Testes:
- caso de colisao real com mesmo `id_operacao` em canais diferentes validando que nao mistura agregacao.

## PR-B (P1) - Filtros de negocio em relatorios

Escopo:
1. `turnos`: filtros `fechado`, `responsavel_id`, `operador_id`.
2. `vendas`: filtros por meio (`id_finalizador`/`meio_pagamento`).
3. testes de autorizacao + consistencia de somas.

## PR-C (P1/P2) - Endpoints analiticos adicionais

Escopo:
1. endpoint `vendedor x loja` por periodo.
2. documentacao API.

---

## 7) Checklist de aceite tecnico

1. Colisao de `id_operacao` + `line_id` entre canais nao mistura linhas no banco.
2. `GET /pdv/reports/vendas?canal=HIPER_CAIXA` retorna apenas agregados do canal caixa.
3. `GET /pdv/reports/vendas?canal=HIPER_LOJA` retorna apenas agregados do canal loja.
4. `falta_caixa.total` negativo aparece corretamente como sobra.
5. Payload com header/body mismatch retorna comportamento esperado e loga motivo.
6. Suite de testes PDV passa em ambiente com DB de teste acessivel.

---

## 8) Decisoes operacionais recomendadas

1. Tratar cancelamento como \"suspeita\" por snapshot ate evento dedicado v3.1.
2. Monitorar lojas silenciosas e divergencia brusca de proporcao caixa/loja.
3. Manter docs de contrato versionadas por release do agente.


