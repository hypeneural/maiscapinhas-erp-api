# PR-44 - P1: Classificacao falta/sobra no fechamento

Status: `done_tecnico`  
Prioridade: `P1`  
Dependencias: PR-42

## Objetivo
Padronizar semantica de fechamento para `falta_caixa` com suporte explicito a valor negativo (sobra).

## Escopo in
- Campo derivado na resposta de turnos.
- Regra unificada de classificacao.
- Testes de regressao.

## Escopo out
- Alteracao de regra de negocio no agente.

## Checklist tecnico

## 1) Regra funcional
- [x] Definir regra final:
- [x] `total_falta > 0` => `FALTA`
- [x] `total_falta < 0` => `SOBRA`
- [x] `total_falta = 0` => `CONFERIDO`
- [x] Garantir uso de valor absoluto para exibicao de `SOBRA` quando necessario.

## 2) API de turnos
- [x] Adicionar campo `falta_caixa_tipo` por turno.
- [x] Adicionar campo opcional `falta_caixa_valor_absoluto`.
- [x] Garantir consistencia no bloco `summary` (se incluir classificacao agregada).

## 3) Validacao de dados
- [x] Confirmar que nenhuma transformacao zera valor negativo no processamento.
- [x] Confirmar que DB aceita negativos em todos os campos usados.

## 4) Testes
- [x] Teste com `total_falta = 30.00` retorna `FALTA`.
- [x] Teste com `total_falta = -15.50` retorna `SOBRA`.
- [x] Teste com `total_falta = 0.00` retorna `CONFERIDO`.
- [x] Teste de compatibilidade para consumidores antigos (campo novo nao quebra contrato atual).

## 5) Documentacao
- [x] Atualizar doc de API de turnos com exemplos dos 3 estados.

## Criterio de aceite
- Fechamento de turno comunica corretamente falta vs sobra sem ambiguidade.

## Riscos e mitigacoes
- Risco: front interpretar sinal e tipo ao mesmo tempo e duplicar regra.
- Mitigacao: documentar claramente qual campo usar para logica e qual para exibicao.
