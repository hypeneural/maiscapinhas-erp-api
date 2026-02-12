# PR-47 - P1: Observabilidade de warnings do agente (GESTAO_DB_FAILURE)

Status: `done_tecnico`  
Prioridade: `P1`  
Dependencias: PR-31/41 (ingest + persistencia v3)

## Objetivo
Transformar `integrity.warnings[]` em sinal operacional confiavel no backend, com foco em indisponibilidade do banco de Gestao (`HIPER_LOJA`).

## Escopo in
- Mapear warning `GESTAO_DB_FAILURE` para `risk_flags`.
- Expor e monitorar esse risco nos fluxos administrativos.
- Garantir que esse warning nao cause rejeicao de payload.

## Escopo out
- Correcao do agente emissor.
- Reprocessamento automatico de dados de loja faltantes.

## Checklist tecnico

## 1) Ingestao e persistencia
- [x] Ler `integrity.warnings[]` do payload.
- [x] Mapear warning com prefixo `GESTAO_DB_FAILURE` para risk flag `gestao_db_failure`.
- [x] Persistir warnings brutos em `pdv_syncs.warnings`.
- [x] Garantir resposta `201` para payload valido mesmo com warning presente.

## 2) API / visibilidade operacional
- [x] Confirmar filtro/admin para `risk_flag=gestao_db_failure`.
- [x] Atualizar dashboard operacional para destacar incidencias recentes.
- [x] Definir threshold de alerta (ex.: > 3 ocorrencias por loja em 30 min).
- [x] Incluir monitoramento de warnings de qualidade adicionais (`vendedor_null`, `meio_pagamento_null`) em metrics admin.

## 3) Regras de negocio
- [x] Documentar que warning de gestao nao deve "zerar" metricas de loja automaticamente.
- [x] Em agregacoes de KPI, tratar warning como dado potencialmente incompleto.

## 4) Testes
- [x] Teste de ingestao: payload com `GESTAO_DB_FAILURE` adiciona `gestao_db_failure` em `risk_flags`.
- [x] Teste admin: consulta por `risk_flag=gestao_db_failure` retorna syncs esperados.
- [x] Teste unitario do mapeamento de warnings (`warningRiskFlags`).
- [x] Teste unitario do monitor: gera issue `gestao_db_failure_high` acima do threshold.

## Criterio de aceite
- Time operacional consegue detectar rapidamente falha de coleta do canal `HIPER_LOJA`.
- Nenhum payload valido e descartado apenas por warning operacional.

## Evidencias
- Filtro admin por risk flag implementado: `GET /api/v1/admin/pdv/syncs?risk_flag=gestao_db_failure`.
- Documentacao de KPI atualizada em `docs/API_PDV_REPORTS_V3.md` (secao 6).
- Monitor operacional com threshold dedicado: `monitor_max_gestao_db_failures_30m`.
- Mapping no ingest de warnings:
  - `Vendedor NULL` -> `vendedor_null`
  - `Meio de pagamento NULL` -> `meio_pagamento_null`

## Riscos e mitigacoes
- Risco: excesso de alerta em flaps curtos da conexao de gestao.
- Mitigacao: cooldown e janela minima para alertas agregados por loja.
