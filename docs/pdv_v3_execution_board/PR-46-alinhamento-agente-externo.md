# PR-46 - Externo: Alinhamento com time do agente JSON

Status: `done_externo_monitorado`  
Prioridade: `P0` no projeto do agente / `coord` neste repo  
Dependencias: nenhuma (track paralelo)

## Objetivo
Fechar itens fora do backend Laravel que impactam contrato e confiabilidade de integracao.

## Escopo in
- Correcao do header de schema no agente.
- Definicao de payloads oficiais de regressao.
- Alinhamento de roadmap (v3.1).

## Escopo out
- Implementacao no backend Laravel.

## Checklist de alinhamento

## 1) Header de schema
- [x] Corrigir no agente: `X-PDV-Schema-Version` envia `3.0` (na mesma versao do body).
- [x] Validar em ambiente real com payload bruto (header e body iguais).
- [ ] Compartilhar evidencias de request real (sanitizado) para anexar na trilha de deploy.

## 2) Pacote de payloads oficiais
- [x] Entregar payload `sales` (caixa).
- [x] Entregar payload `sales` (loja).
- [x] Entregar payload `mixed` com colisao de `id_operacao`.
- [x] Entregar payload `turno_closure`.
- [x] Entregar payload com replay de snapshot.

## 3) Semantica de regras
- [x] Confirmar regra oficial de desempate de `responsavel` (segue nao deterministico ate v3.1).
- [x] Confirmar plano para evento explicito de cancelamento (v3.1).
- [x] Confirmar politica de compatibilidade de novos canais.
- [x] Confirmar que `turnos[]` agora inclui campos v3 em tempo real (`duracao_minutos`, `periodo`, `qtd_vendas`, `total_vendas`, `qtd_vendedores`).
- [x] Confirmar fix de troco em `HIPER_LOJA` para multi-finalizador.
- [x] Confirmar warning operacional `GESTAO_DB_FAILURE` em `integrity.warnings[]`.

## 4) Governanca de contrato
- [x] Registrar SLA de aviso para breaking change.
- [x] Publicar changelog por versao do schema.
- [x] Manter JSON schema oficial versionado por release.

## Criterio de aceite
- Backend recebe payload v3 sem mismatch de header/body.
- Time backend possui fixtures oficiais para regressao automatizada.
