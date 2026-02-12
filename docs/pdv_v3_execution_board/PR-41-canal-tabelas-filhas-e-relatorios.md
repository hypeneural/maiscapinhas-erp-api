# PR-41 - P0: Canal nas tabelas filhas e joins de relatorio

Status: `done_tecnico`  
Prioridade: `P0`  
Dependencias: PR-32, PR-39

## Objetivo
Eliminar risco de mistura de dados entre `HIPER_CAIXA` e `HIPER_LOJA` em itens/pagamentos e em agregacoes de relatorio.

## Escopo in
- Migration com `canal` em `pdv_venda_itens` e `pdv_venda_pagamentos`.
- Ajuste de chaves unicas para incluir `canal`.
- Propagacao de `canal` no `ProcessPdvSyncJob`.
- Ajuste dos joins/aggregates em `PdvReportsController`.
- Testes de colisao cross-canal.

## Escopo out
- Novos filtros de negocio e novos endpoints.

## Checklist tecnico

## 1) Migration de schema (filhas)
- [x] Criar migration `2026_02_12_000330_add_canal_to_pdv_venda_children_tables.php`.
- [x] Adicionar `canal` (`string(20)`, default `HIPER_CAIXA`) em `pdv_venda_itens`.
- [x] Adicionar `canal` (`string(20)`, default `HIPER_CAIXA`) em `pdv_venda_pagamentos`.
- [x] Backfill `canal` via join com `pdv_vendas` por `store_pdv_id + id_operacao`.
- [x] Tratar linhas sem match no backfill com fallback `HIPER_CAIXA`.

## 2) Recriar constraints e indices
- [x] `pdv_venda_itens`:
- [x] Dropar unique antigo `(store_pdv_id, line_id)`.
- [x] Criar unique novo `(store_pdv_id, canal, line_id)`.
- [x] Dropar unique antigo `(store_pdv_id, id_operacao, row_hash)`.
- [x] Criar unique novo `(store_pdv_id, canal, id_operacao, row_hash)`.
- [x] Dropar unique antigo `(store_pdv_id, id_operacao, line_no)`.
- [x] Criar unique novo `(store_pdv_id, canal, id_operacao, line_no)`.
- [x] Criar index `canal`.
- [x] `pdv_venda_pagamentos`:
- [x] Dropar unique antigo `(store_pdv_id, line_id)`.
- [x] Criar unique novo `(store_pdv_id, canal, line_id)`.
- [x] Dropar unique antigo `(store_pdv_id, id_operacao, row_hash)`.
- [x] Criar unique novo `(store_pdv_id, canal, id_operacao, row_hash)`.
- [x] Dropar unique antigo `(store_pdv_id, id_operacao, line_no)`.
- [x] Criar unique novo `(store_pdv_id, canal, id_operacao, line_no)`.
- [x] Criar index `canal`.

## 3) Processamento do webhook (job)
- [x] Em `ProcessPdvSyncJob`, incluir `canal` em cada `itemRow`.
- [x] Em `ProcessPdvSyncJob`, incluir `canal` em cada `pagamentoRow`.
- [x] Ajustar UPSERT de itens por `line_id` para `['store_pdv_id', 'canal', 'line_id']`.
- [x] Ajustar UPSERT de itens fallback para `['store_pdv_id', 'canal', 'id_operacao', 'row_hash']`.
- [x] Ajustar UPSERT de pagamentos por `line_id` para `['store_pdv_id', 'canal', 'line_id']`.
- [x] Ajustar UPSERT de pagamentos fallback para `['store_pdv_id', 'canal', 'id_operacao', 'row_hash']`.
- [x] Revisar listas de colunas de update para incluir `canal`.

## 4) Relatorios (isolar canal corretamente)
- [x] Ajustar agregacao de itens para agrupar por `store_pdv_id + canal + id_operacao`.
- [x] Ajustar agregacao de pagamentos para agrupar por `store_pdv_id + canal + id_operacao`.
- [x] Ajustar joins com `pdv_vendas` para incluir `canal`.
- [x] Ajustar `whereExists` de filtro por vendedor para incluir `canal`.
- [x] Revisar qualquer soma de `itens/pagamentos` que ainda una so por `store_pdv_id + id_operacao`.

## 5) Testes automatizados
- [x] Criar teste unitario: mesmo `line_id` em canais diferentes nao colide em itens.
- [x] Criar teste unitario: mesmo `line_id` em canais diferentes nao colide em pagamentos.
- [x] Criar teste unitario: replay no mesmo canal atualiza sem duplicar.
- [x] Criar/ajustar teste de relatorio para validar agregacao separada por canal.
- [x] Executar suite de unidade do job PDV.

## 6) Validacao manual (smoke)
- [ ] Ingerir payload `mixed` com colisao de `id_operacao`.
- [ ] Confirmar linhas em `pdv_venda_itens` separadas por `canal`.
- [ ] Confirmar linhas em `pdv_venda_pagamentos` separadas por `canal`.
- [ ] Validar `GET /api/v1/pdv/reports/vendas?canal=HIPER_CAIXA`.
- [ ] Validar `GET /api/v1/pdv/reports/vendas?canal=HIPER_LOJA`.

## Criterio de aceite
- Nao existe sobrescrita cross-canal em itens/pagamentos.
- Relatorios nao misturam dados entre canais.
- Testes de colisao passam.

## Riscos e mitigacoes
- Risco: migration em tabela com alto volume.
- Mitigacao: janela de deploy, backup logico, monitoramento de tempo de alter table.

## Rollback
- Migration deve ter `down()` com rollback de constraints e colunas.
- Em incidente, restaurar backup e reverter deploy de codigo.
