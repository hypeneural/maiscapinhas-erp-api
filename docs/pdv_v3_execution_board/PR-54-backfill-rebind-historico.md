# PR-54 - Backfill/Rebind Historico (store_id NULL, vendedor_user_id NULL)

Status: `done`  
Prioridade: `P1`  
Tipo: `backend-ops`  
Dependencias: `PR-50` (resolver CNPJ/Login), `PR-51` (persistencia login)

## Objetivo
Corrigir dados historicos que ficaram "desvinculados" por ocorrerem **antes** do bootstrap de mappings ou antes do agente enviar `cnpj/login`.

Casos tipicos:
- `pdv_syncs.store_id IS NULL` (risk flag `store_mapping_missing`), mesmo com o payload tendo `store.cnpj`.
- `pdv_venda_itens.vendedor_user_id IS NULL` mesmo com `vendedor_login` presente e mapping ativo.
- `pdv_turnos.operador_user_id IS NULL` mesmo com `operador_login` presente e mapping ativo.

Sem backfill, os dados continuam no banco, mas:
- ficam invisiveis em relatorios filtrados por `store_id`
- quebram ranking por vendedor quando o `vendedor_user_id` e necessario no futuro

## Estrategia recomendada (segura)

### 1) Rebind de loja (CNPJ first)
Atualizar `pdv_syncs.store_id` (e opcionalmente `pdv_vendas.store_id`, `pdv_venda_itens.store_id`, `pdv_venda_pagamentos.store_id`, `pdv_turnos.store_id`) para registros que hoje estao nulos.

Fonte de verdade:
- `pdv_sync_payloads.payload` -> `store.cnpj` e `store.id_ponto_venda`
- resolver `PdvStoreResolver` (mesma logica do ingress)

### 2) Backfill de vendedor_user_id por login
Atualizar `pdv_venda_itens.vendedor_user_id` onde:
- `vendedor_user_id IS NULL`
- `vendedor_login IS NOT NULL`
- existe mapping ativo em `pdv_user_mappings` para o login (case-insensitive)

Obs:
- evitar fallback por `pdv_user_id` neste backfill para nao correr risco de colisao cross-store.

### 3) Backfill de operador_user_id por login
Atualizar `pdv_turnos.operador_user_id` (quando coluna existir) por `operador_login` -> mapping.

## Implementacao (tarefas)

1. Novo comando Artisan
- [x] criar `app/Console/Commands/PdvBackfillBindingsCommand.php`
  - opcoes:
    - `--dry-run`
    - `--since=YYYY-MM-DD` (janela)
    - `--only=stores|vendedores|operadores`
    - `--limit=N`
  - logs com contadores: `updated`, `skipped`, `ambiguous`, `missing`

2. SQL/updates
- [x] rebind `pdv_syncs.store_id` a partir do payload (`pdv_sync_payloads.payload.store.cnpj/alias`)
- [x] backfill `pdv_venda_itens.vendedor_user_id` por `vendedor_login` -> `pdv_user_mappings.pdv_user_login`
- [x] backfill `pdv_turnos.operador_user_id` por `operador_login` -> mapping

3. Observabilidade
- [x] comando imprime contadores por bloco (stores/vendedores/operadores)

4. Documentacao/runbook
- [x] adicionar no runbook: como rodar em producao e como validar depois via queries

## Criterios de aceite
- [x] comando idempotente (rodar 2x nao cria inconsistencias)
- [x] modo dry-run mostra o que seria alterado sem escrever
- [ ] reduzir `pdv_syncs` com `store_id IS NULL` para proximo de 0 (para lojas ativas) (depende de execucao em prod)
- [ ] reduzir `pdv_venda_itens` com `vendedor_user_id IS NULL` quando `vendedor_login` existe (depende de execucao em prod)

Obs: a implementacao esta pronta. Falta apenas rodar em producao (janela segura) conforme runbook em `docs/PDV_V3_ENV_QUEUE_RUNBOOK.md`.
