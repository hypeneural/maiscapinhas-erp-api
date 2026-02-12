# PR-31 - Habilitar contrato v3 no ingress

Status: `done`  
Prioridade: `P0`  
Dependencias: Gate G0 concluido

Observacao: validacao automatizada de ingestao v3 concluida; regressao completa de Feature com MySQL de teste permanece pendente de ambiente.

## Objetivo
Aceitar payload v3.0 na borda de ingestao sem quebrar compatibilidade com v2.0.

## Escopo in
- Config de versoes suportadas (`2.0`, `3.0`).
- Registro de schema v3 no backend.
- Validacoes de request para novos campos v3.
- Testes de ingestao para v3.

## Escopo out
- Persistencia de campos v3 (PR-32+).
- Logica de snapshots (PR-34/35).

## Checklist tecnico

## 1) Config e ambiente
- [x] Atualizar `config/pdv.php` para aceitar `3.0` em `supported_schema_versions`.
- [x] Adicionar mapeamento `json_schema_files['3.0']`.
- [x] Atualizar `.env.example`:
- [x] `PDV_SUPPORTED_SCHEMA_VERSIONS=2.0,3.0`
- [x] `PDV_JSON_SCHEMA_FILE_3_0=docs/schema_v3.0.json`

## 2) Schema JSON
- [x] Criar arquivo `docs/schema_v3.0.json`.
- [x] Garantir campos v3:
- [x] `vendas[].canal`
- [x] `snapshot_turnos[]`
- [x] `snapshot_vendas[]`
- [x] `ops.loja_count`
- [x] `ops.loja_ids`
- [x] `turnos[].responsavel`
- [x] `event_type` coerente com contrato

## 3) Validacao de request
- [x] Atualizar `app/Http/Requests/Pdv/PdvSyncIngestRequest.php`.
- [x] Aceitar novos campos sem bloquear v2.
- [x] Tratar nullability conforme contrato do time JSON.

## 4) Ingestao e header
- [x] Validar fluxo com `schema_version=3.0` no payload.
- [x] Validar fluxo com `X-PDV-Schema-Version: 3.0`.
- [x] Manter comportamento de erro atual para mismatch de header/payload.

## 5) Testes
- [x] Criar teste: payload v3 valido retorna `201`.
- [x] Criar teste: header `3.0` + payload `3.0` passa.
- [x] Criar teste: header `3.0` + payload `2.0` retorna `422`.
- [ ] Garantir regressao: suite atual v2 permanece verde.

## Criterio de aceite
- Backend aceita v2 e v3 em paralelo na ingestao.
- Nenhum teste atual de v2 quebra.

## Arquivos alvo esperados
- `config/pdv.php`
- `.env.example`
- `docs/schema_v3.0.json`
- `app/Http/Requests/Pdv/PdvSyncIngestRequest.php`
- `tests/Feature/Api/V1/PdvSyncWebhookTest.php`
- `tests/Unit/Support/PdvJsonSchemaValidatorTest.php` (se necessario)

## Riscos e mitigacoes
- Risco: schema v3 incompleto no primeiro commit.
- Mitigacao: habilitar validacao de schema v3 primeiro em homologacao.

## Validacao manual sugerida
- [ ] Enviar payload v2 de referencia.
- [ ] Enviar payload v3 de referencia.
- [ ] Conferir `pdv_syncs.schema_version` nos registros criados.
