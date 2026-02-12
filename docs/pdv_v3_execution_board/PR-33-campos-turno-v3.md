# PR-33 - Campos de turno v3

Status: `done`  
Prioridade: `P0`  
Dependencias: PR-31

Observacao: validado com suite dedicada do job (`tests/Unit/Jobs/ProcessPdvSyncJobTurnoV3Test.php`).

## Objetivo
Persistir os campos novos de turno do contrato v3, inclusive `responsavel`.

## Escopo in
- Novas colunas em `pdv_turnos`.
- Mapeamento desses campos no processamento principal de `turnos[]`.

## Escopo out
- Processamento de `snapshot_turnos[]` (PR-34).

## Checklist tecnico

## 1) Migration em `pdv_turnos`
- [x] Adicionar `duracao_minutos INT NULL`.
- [x] Adicionar `periodo VARCHAR(20) NULL`.
- [x] Adicionar `responsavel_pdv_id BIGINT NULL`.
- [x] Adicionar `responsavel_nome VARCHAR(200) NULL`.
- [x] Adicionar `qtd_vendas INT NOT NULL DEFAULT 0`.
- [x] Adicionar `total_vendas DECIMAL(14,2) NOT NULL DEFAULT 0`.
- [x] Adicionar `qtd_vendedores INT NOT NULL DEFAULT 0`.
- [x] Criar indices:
- [x] `(store_id, periodo)`
- [x] `(store_id, responsavel_pdv_id)`

## 2) Job de processamento
- [x] Atualizar `processTurnos()` para mapear:
- [x] `turnos[].responsavel.id_usuario`
- [x] `turnos[].responsavel.nome`
- [x] `turnos[].duracao_minutos`
- [x] `turnos[].periodo`
- [x] `turnos[].qtd_vendas`
- [x] `turnos[].total_vendas`
- [x] `turnos[].qtd_vendedores`
- [x] Manter null-safe para `responsavel`.
- [x] Incluir novas colunas em `turnoUpdateColumns`.

## 3) Testes
- [x] Turno com `responsavel` preenchido.
- [x] Turno com `responsavel: null`.
- [x] Update de turno existente atualizando metricas sem duplicar.

## 4) Compatibilidade
- [x] Garantir que payload v2 (sem campos novos) continua processando.

## Criterio de aceite
- Campos de turno v3 persistem corretamente em `pdv_turnos`.
- Upsert continua idempotente.

## Arquivos alvo esperados
- `database/migrations/*_add_v3_fields_to_pdv_turnos*.php`
- `app/Jobs/ProcessPdvSyncJob.php`
- `tests/*`

## Riscos e mitigacoes
- Risco: inconsistencias de tipos ou casas decimais.
- Mitigacao: validar com payload real anonimo antes do deploy.

## Validacao manual sugerida
- [ ] Enviar payload com `responsavel` e `periodo`.
- [ ] Conferir persistencia em `pdv_turnos`.
