# PDV v3 Execution Board

Data base: 2026-02-11  
Fonte principal: `docs/TASK_PR_ROADMAP_WEBHOOK_PDV_V3_DETALHADO_2026-02-11.md`

## Objetivo
Este board organiza a execucao em PRs independentes, com contexto suficiente para evitar retrabalho e perda de informacao.

## Pre requisito operacional (Gate G0)
Antes de iniciar PR funcional v3, garantir:
- PR-18 concluido (worker persistente)
- PR-19 concluido (scheduler recorrente)
- PR-21 concluido (monitor e alerta ativo)

## Ordem recomendada
1. [PR-31 - Habilitar contrato v3 no ingress](./PR-31-contrato-v3-ingress.md)
2. [PR-32 - Canal e chave canonica de vendas](./PR-32-canal-chave-canonica-vendas.md)
3. [PR-33 - Campos de turno v3](./PR-33-campos-turno-v3.md)
4. [PR-34 - Snapshot de turnos com upsert](./PR-34-snapshot-turnos-upsert.md)
5. [PR-35 - Snapshot de vendas e tabela resumo](./PR-35-snapshot-vendas-resumo.md)
6. [PR-36 - Ops loja e consistencia de event_type](./PR-36-ops-loja-consistencia-event-type.md)
7. [PR-37 - Tabelas master e auto cadastro](./PR-37-tabelas-master-normalizacao.md)
8. [PR-38 - Observabilidade v3](./PR-38-observabilidade-v3.md)
9. [PR-39 - Endpoints PDV v3 de consulta](./PR-39-endpoints-pdv-v3-consulta.md)
10. [PR-40 - Hardening e go live controlado](./PR-40-hardening-go-live.md)

## Estado rapido
- [x] PR-31
- [x] PR-32
- [x] PR-33
- [x] PR-34
- [x] PR-35
- [x] PR-36
- [x] PR-37
- [x] PR-38
- [x] PR-39
- [ ] PR-40

## Definicoes tecnicas congeladas
- Chave canonica de venda: `(store_pdv_id, canal, id_operacao)`.
- `id_operacao` pode colidir entre `HIPER_CAIXA` e `HIPER_LOJA`.
- `canal` sempre presente no v3 (`HIPER_CAIXA` ou `HIPER_LOJA`).
- `snapshot_turnos[]` e `snapshot_vendas[]` sao fonte de verdade atual.
- `store.id_ponto_venda` e chave global da loja; `store.alias` pode mudar.
- `line_id` e estavel; fallback por `row_hash` deve permanecer.
