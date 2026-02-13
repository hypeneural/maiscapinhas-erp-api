# PR-52 - Observabilidade e Documentacao do Binding v3.1

Status: `done`  
Prioridade: `P1`  
Tipo: `backend-ops-docs`  
Dependencia: `PR-50`, `PR-51`

## Objetivo
Dar visibilidade operacional para a nova logica de identidade (`cnpj/login`) e atualizar documentacao publica/operacional.

## Contexto
- A mudanca de contrato so gera valor se conseguirmos medir:
  - quando o binding usou chave forte (`cnpj/login`)
  - quando caiu em fallback (`id/alias`)
  - onde ainda ha risco de mapeamento

## Escopo tecnico
- Expandir monitoramento e administracao PDV com KPIs de binding.
- Atualizar docs internas e Scribe com campos novos.
- Fechar checklist E2E de homologacao.

## Tarefas
- [x] `PdvOpsMonitorCommand`:
- [x] adicionar metricas de resolucao por CNPJ (`store_resolution_cnpj_rate`).
- [x] adicionar metricas de resolucao por login (`user_resolution_login_rate`).
- [x] manter contadores de fallback por id.
- [x] stale stores: calcular por `store_id` (evita falso stale por drift/colisao de alias).
- [x] `PdvSyncAdminController`:
- [x] exibir contadores de novos risk flags de identidade.
- [ ] endpoint de saude com resumo de ambiguidades ativas (sugestao de backlog; nao bloqueia go-live).
- [x] Atualizar docs:
- [x] `docs/API_PDV_REPORTS_V3.md` com filtros `store_alias` e campos de login (se aplicavel).
- [x] `docs/PDV_V3_ENV_QUEUE_RUNBOOK.md` com queries de diagnostico de mapping v3.1.
- [x] Scribe:
- [x] documentar `store.cnpj` e `*.login` no endpoint de webhook.
- [x] incluir exemplos reais v3.1 (response calls restritas a endpoints PDV, para evitar vazamento de dados).
- [x] Criar roteiro E2E:
- [x] caso alias errado + cnpj correto.
- [x] caso id_usuario divergente + login correto.
- [x] caso login ausente e fallback por id.

## Criterios de aceite
- [x] Dashboard/admin mostra taxa de binding por chave forte.
- [x] Docs refletem o contrato v3.1 sem ambiguidade.
- [x] Checklist E2E executado e anexado em doc de validacao.

## Verificacao manual
- [x] Rodar monitor e confirmar novas metricas no JSON.
- [x] Validar `/docs` com exemplos v3.1 publicados (artefatos gerados em `public/docs`).
- [x] Executar 3 cenarios E2E e anexar evidencias (request/response + SQL).

Evidencias anexadas:
- `docs/VALIDACAO_E2E_PDV_PRODUCAO_POS_NORMALIZACAO_2026-02-13.md`

## Execucao realizada
- `app/Console/Commands/PdvOpsMonitorCommand.php`:
  - bloco `identity_resolution` adicionado ao JSON do monitor.
  - taxas `store_resolution_cnpj_rate` e `user_resolution_login_rate`.
  - contadores de fallback/ausencia/mismatch por identidade.
  - stale stores agora baseados em `store_id` (nao depende de `alias`).
- `app/Http/Controllers/Api/V1/Admin/PdvSyncAdminController.php`:
  - novos contadores de risk flags de identidade.
  - resumo `identity_resolution` (janela de 24h) no endpoint de metricas admin.
- `app/Http/Controllers/Api/V1/PdvSyncController.php`:
  - docblocks Scribe atualizados com `store.cnpj` e campos `*.login`.
- `config/scribe.php`:
  - response calls restritas a endpoints PDV e health/version (evita exemplos com dados sensiveis fora do escopo).
- `docs/API_PDV_REPORTS_V3.md`:
  - filtros `store_alias` documentados em todos os endpoints de relatorio.
  - secao de observacoes v3.1 para campos de login operacionais.
- `docs/PDV_V3_ENV_QUEUE_RUNBOOK.md`:
  - runbook atualizado para `3.0 + 3.1`.
  - queries SQL de diagnostico de binding v3.1 adicionadas.
- `tests/Unit/Console/PdvOpsMonitorCommandTest.php`:
  - teste novo para validar taxas/contadores de identidade.
