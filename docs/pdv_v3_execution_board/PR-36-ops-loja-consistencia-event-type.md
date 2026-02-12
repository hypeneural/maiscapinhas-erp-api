# PR-36 - Ops loja e consistencia de event_type

Status: `done`  
Prioridade: `P0`  
Dependencias: PR-31

Observacao: cobertura automatizada principal em testes de ingestao (`tests/Feature/Api/V1/PdvSyncWebhookTest.php`) e validacao de regressao unitarias do processamento.

## Objetivo
Persistir `ops.loja_count` e `ops.loja_ids`, e reforcar validacao semantica por `event_type`.

## Escopo in
- Novos campos em `pdv_syncs` para operacoes do canal loja.
- Regras de consistencia semantica com risk flags.
- Exposicao desses dados no admin.

## Escopo out
- Rejeicao hard de payload inconsistente (primeira fase deve ser observabilidade).

## Checklist tecnico

## 1) Migration em `pdv_syncs`
- [x] Adicionar `ops_loja_count INT NOT NULL DEFAULT 0`.
- [x] Adicionar `ops_loja_ids JSON NULL`.
- [x] Criar indice para `ops_loja_count` se necessario para consultas admin.

## 2) Controller de ingestao
- [x] Ler `ops.loja_count`.
- [x] Ler `ops.loja_ids`.
- [x] Persistir campos novos em `pdv_syncs`.
- [x] Validar tipo de `ops.loja_ids` (array de int).

## 3) Regras de consistencia de evento
- [x] Criar validacoes nao bloqueantes com risk flags:
- [x] `event_type=turno_closure` com `vendas` nao vazio.
- [x] `event_type=mixed` sem venda.
- [x] `event_type=mixed` sem turno fechado.
- [x] Garantir logs com contexto do erro sem bloquear processamento na primeira fase.

## 4) Admin e metrica
- [x] Expor `ops_loja_count`/`ops_loja_ids` no endpoint admin de syncs.
- [ ] Opcional: incluir no endpoint metrics agregacao por loja/canal.

## 5) Testes
- [x] Persistencia correta de `ops_loja_*`.
- [x] Criacao de risk flag em payload inconsistente.
- [x] Regressao de ingestao valida continua verde.

## Criterio de aceite
- Sync passa a guardar contagem e ids de operacoes do canal loja.
- Inconsistencias de `event_type` ficam rastreaveis via flags.

## Arquivos alvo esperados
- `database/migrations/*_add_ops_loja_to_pdv_syncs*.php`
- `app/Http/Controllers/Api/V1/PdvSyncController.php`
- `app/Http/Controllers/Api/V1/Admin/PdvSyncAdminController.php`
- `tests/*`

## Riscos e mitigacoes
- Risco: excesso de flag por pequenas variacoes de payload.
- Mitigacao: iniciar como warning operacional, sem bloqueio.

## Validacao manual sugerida
- [ ] Enviar payload com `ops.loja_*`.
- [ ] Conferir colunas em `pdv_syncs`.
