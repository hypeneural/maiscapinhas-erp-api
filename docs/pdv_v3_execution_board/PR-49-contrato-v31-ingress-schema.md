# PR-49 - Contrato v3.1 no Ingress (Schema/Versionamento)

Status: `in_progress`  
Prioridade: `P0`  
Tipo: `backend-api`  
Dependencia: nenhuma

## Objetivo
Permitir ingestao segura do contrato atual do agente (payload `3.0` enriquecido com `cnpj/login`) sem quebrar compatibilidade de schema/versionamento.

## Contexto
- O agente confirmou que mantera `schema_version=3.0` por enquanto, mesmo enviando campos extras (`store.cnpj` e `*.login`).
- O backend precisa aceitar `duracao_minutos=null` em turnos abertos e manter schema consistente para `3.0`.
- Sem este PR, parte dos payloads reais continua gerando `422` por mismatch de validacao.

## Escopo tecnico
- Ajustar matriz de versoes suportadas no backend.
- Incluir arquivo de schema `3.1`.
- Garantir regra clara header/body:
  - `X-PDV-Schema-Version` deve casar com `payload.schema_version`.
- Atualizar mensagens de erro para facilitar troubleshooting.

## Tarefas
- [x] Definir estrategia de rollout de versao:
- [x] Opcao A (transicao): aceitar `3.0` e `3.1` por janela curta.
- [x] Opcao B (corte): manter `3.1` habilitado, mas sem exigir troca imediata da versao no agente.
- [x] Atualizar config de versoes suportadas no backend.
- [x] Incluir `docs/schema_v3.1.json` no projeto.
- [x] Atualizar mapeamento de schema file para `3.1`.
- [x] Garantir validacao ingress para:
- [x] header ausente.
- [x] header invalido.
- [x] header/body mismatch.
- [x] Reforcar logs com `schema_header`, `schema_body`, `request_id`, `sync_id`.
- [x] Atualizar testes de webhook para cenarios `3.1`.

## Criterios de aceite
- [x] Payload `3.1` valido retorna `201 created`.
- [x] Header/body mismatch retorna `422` com motivo explicito.
- [x] Payload `3.0` segue politica definida (aceita, inclusive com campos extras de identidade).
- [x] `pdv_syncs.schema_version` persiste corretamente.

## Verificacao manual
- [ ] Enviar payload minimo `3.1` com `store.cnpj` e validar ingestao.
- [ ] Enviar payload `3.1` com header `3.0` e validar `422`.
- [ ] Revisar `storage/logs` com os campos de versao.
- [ ] Enviar payload `3.0` com `store.cnpj`/`login` e validar ingestao com schema ativo.
- [ ] Enviar payload `3.0` com `duracao_minutos=null` em turno aberto e validar `201`.

## Execucao realizada
- `config/pdv.php` atualizado para suportar `3.0` + `3.1`.
- `docs/schema_v3.1.json` criado e registrado no validator.
- `PdvSyncIngestRequest` atualizado para aceitar `duracao_minutos` nullable em `turnos`/`snapshot_turnos`.
- `PdvSyncIngestRequest` ajustado para `store.cnpj` nullable.
- `docs/schema_v3.0.json` atualizado para aceitar `store.cnpj` e campos `login` opcionais.
- `PdvSyncController` DocBlock atualizado para refletir contrato atual em payloads `3.0/3.1`.
- Testes de regressao adicionados em `tests/Feature/Api/V1/PdvSyncWebhookTest.php`.
