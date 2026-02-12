# Decisoes Backend - Pos Respostas do Time Python (PDV v3)

Data: 2026-02-12  
Projeto: `maiscapinhas-erp-api`  
Base: respostas oficiais do time `pdv-sync-agent v3.0.0` + validacao E2E em producao

---

## 1) Conclusao executiva

Integracao PDV v3 esta funcional em producao e aderente ao contrato principal.  
As respostas do time Python confirmam os pontos criticos e trazem 3 ajustes praticos de backend:

1. Tratar `ops.*` como invariante `>=` (nao `==`) versus `vendas[]`.  
2. Formalizar monitoramento para warnings de qualidade (`Vendedor NULL`, `Meio pagamento NULL`).  
3. Reforcar capacidade para payloads grandes (backlog sem chunking no v3.0).

---

## 2) Decisoes finais por tema

## D1 - Idempotencia por `sync_id`

Decisao:
- Manter idempotencia por `integrity.sync_id` como regra principal.
- Considerar replay manual por reset de `state.json` como risco operacional raro, nao como erro de contrato.

Acao backend:
- Nenhuma mudanca de codigo obrigatoria agora.
- Em auditoria, usar `request_id`, `received_at`, `agent.sent_at` para investigar eventual replay manual.

## D2 - Regra `ops.*` x `vendas[]`

Decisao:
- Validacao correta:
  - `ops.count >= vendas HIPER_CAIXA`
  - `ops.loja_count >= vendas HIPER_LOJA`
- Nao usar igualdade estrita como erro.

Motivo:
- Pode existir operacao com todos os itens cancelados (`op.cancelado=0`, itens `cancelado=1`), aparecendo em `ops.ids` mas nao em `vendas[]`.

Acao backend:
- Se houver checks de consistencia operacional, ajustar para `>=`.
- Divergencia deve virar warning de qualidade, nao rejeicao do payload.

## D3 - Taxonomia de `integrity.warnings[]`

Decisao:
- No v3.0, warnings continuam texto livre.
- Prefixos confirmados atualmente:
  - `GESTAO_DB_FAILURE:`
  - `Vendedor NULL ...`
  - `Meio de pagamento NULL ...`

Acao backend:
- Manter parser por prefixo/contains no ingest.
- Ja mapeado: `GESTAO_DB_FAILURE -> risk_flag=gestao_db_failure`.
- Mapeado tambem:
  - `Vendedor NULL` -> `risk_flag=vendedor_null`
  - `Meio de pagamento NULL` -> `risk_flag=meio_pagamento_null`
- Exposto em metrics admin:
  - `risk_flags.vendedor_null`
  - `risk_flags.meio_pagamento_null`

## D4 - Snapshot como fonte de verdade

Decisao:
- Snapshot prevalece sobre dados de negocio persistidos (turnos/vendas resumo).
- Nao sobrescrever metadados de auditoria do backend (`received_at`, `processed_at`, `risk_flags` locais).

Acao backend:
- Manter estrategia atual de UPSERT em `snapshot_turnos[]` e `snapshot_vendas[]`.
- Preservar separacao entre dados de negocio e metadados de processamento.

## D5 - `qtd_vendedores` no detalhe de turno

Decisao:
- Em `turnos[]`, `qtd_vendedores` e placeholder (0) no v3.0.
- Valor confiavel vem de `snapshot_turnos[]`.

Acao backend:
- Em telas/relatorios, considerar snapshot como valor autoritativo para `qtd_vendedores`.
- Nao usar `turnos[].qtd_vendedores` isoladamente para KPI final.

## D6 - Troco em `HIPER_LOJA` com multi-pagamento

Decisao:
- Fix confirmado no agente: troco nao deve mais duplicar entre finalizadores.
- Em Loja, troco vem de operacao e e atribuido a uma unica linha (rn=1).

Acao backend:
- Pode manter soma de `pagamentos[].troco` por venda.
- Monitorar 7 dias para garantir ausencia de regressao.

## D7 - Payload grande / backlog

Decisao:
- No v3.0 nao existe chunking automatico.
- Backlog pode gerar payload unico grande.

Acao backend (imediata):
- Confirmar infraestrutura:
  - `client_max_body_size >= 20M` (nginx/proxy)
  - `post_max_size` e `upload_max_filesize` compativeis
  - timeout de worker/scheduler adequado para payload grande

Acao futura:
- Acompanhar v3.1 para chunking no agente.

## D8 - Timezone

Decisao:
- Agente fixo em `-03:00` (BRT) para todas as lojas atuais.

Acao backend:
- Manter parse/normalizacao atual para UTC.
- Nao criar regra por timezone de loja neste momento.

---

## 3) Itens que permanecem para v3.1 (cross-time)

1. Warning estruturado (`warnings: [{code,message,severity}]`).  
2. Chunking automatico para backlog.  
3. Contrato explicito para cancelamento/correcao (`corrections[]` ou `event_type=cancellation`).  
4. `qtd_vendedores` preciso tambem no `turnos[]` detalhe.

---

## 4) Backlog recomendado (prioridade)

## P1 - Observabilidade de warnings de qualidade
- [x] Mapeamento no ingest:
  - `Vendedor NULL` -> `vendedor_null`
  - `Meio de pagamento NULL` -> `meio_pagamento_null`
- [x] Exposicao no admin metrics (`risk_flags`).
- [ ] Ajustar monitor operacional (`pdv:ops-monitor`) para thresholds especificos desses dois warnings.

## P1 - Hardening de payload grande
- Validar limites reais de infra (proxy/PHP/FPM).
- Criar smoke periodico com payload de backlog maior.

## P2 - Regra de consistencia `ops >= vendas`
- Adicionar check operacional (nao bloqueante) para detectar divergencia alta.

---

## 5) Status de aderencia atual

- [x] Chave canonica de venda com `canal`.
- [x] Filhas (itens/pagamentos) com `canal`.
- [x] Snapshot turnos e vendas com UPSERT.
- [x] `schema_version` v3-only.
- [x] Endpoints de relatorio com filtros funcionais em producao.
- [x] Monitor de `GESTAO_DB_FAILURE`.
- [x] Mapeamento de warnings `Vendedor NULL` e `Meio pagamento NULL`.
- [ ] Hardening formal de limite de payload grande em infra (recomendado).
