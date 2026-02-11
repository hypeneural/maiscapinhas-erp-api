# Alinhamento Tecnico Hiper x Backend (Webhook PDV Sync v2.0)

Data: 2026-02-11  
Base: respostas tecnicas do time Hiper (agente PDV em producao)

## 1) Resumo executivo

O time Hiper confirmou que o agente esta funcional para envio incremental de janelas de 10 minutos com outbox e `sync_id` deterministico, mas ainda ha pontos bloqueantes para contrato final com nosso backend:

1. O agente ainda nao envia HMAC (`X-PDV-Signature` + `X-PDV-Timestamp`).
2. Datetimes sao enviados sem timezone explicito (naive).
3. Itens/pagamentos ainda nao enviam `line_no` estavel.
4. O agente hoje nao diferencia bem tipos de erro HTTP para politica de retry inteligente.

Conclusao: o fluxo base esta pronto, mas o go-live seguro depende de fechamento desses gaps.

## 2) O que foi confirmado como estavel

- `sync_id` deterministico por loja + janela.
- Outbox local em disco com replay automatico.
- Retry com backoff exponencial no envio HTTP.
- `id_operacao` unico por loja.
- `id_turno` GUID imutavel.
- `totais_sistema` de turno acumulado (turno inteiro, nao apenas janela).
- Intervalo de janela `[from, to)` fechado-aberto para evitar duplicacao natural.

## 3) Gaps e impacto tecnico

## 3.1 Seguranca (P0)

- Gap: agente usa Bearer token fixo e nao HMAC.
- Impacto: nosso endpoint atual exige HMAC; sem ajuste no agente o webhook nao entra.
- Acao recomendada: implementar HMAC no agente como prioridade imediata.

Mitigacao temporaria no backend:
- `PDV_ALLOW_BEARER_FALLBACK=true` habilita aceite temporario via Bearer.
- `PDV_BEARER_TOKEN` define token esperado.
- Uso deve ser transitório; objetivo final permanece HMAC.

## 3.2 Tempo/timezone (P0)

- Gap: payload envia datetime sem offset (`-03:00` ou `Z`).
- Impacto: risco de deslocamento temporal em filtros, virada de dia e analise por periodo.
- Acao recomendada: enviar todos os datetimes com timezone explicito (preferencia: ISO-8601 com offset).

Mitigacao temporaria no backend:
- parser trata datetime sem offset como timezone configurado em `PDV_NAIVE_DATETIME_TIMEZONE` (default `America/Sao_Paulo`) e normaliza para UTC.

## 3.3 Idempotencia de filhos (P1)

- Gap: ausencia de `line_no` em `itens[]` e `pagamentos[]`.
- Impacto: maior complexidade para reprocessamento perfeito em falhas parciais.
- Acao recomendada: incluir `line_no` estavel no payload.

Mitigacao temporaria no backend:
- `row_hash` deterministico por linha de item/pagamento para fallback de idempotencia quando `line_no` nao vier.

## 3.4 Retry semanticamente correto (P1)

- Gap: agente tende a enviar para outbox em erros que nao deveriam retry (ex.: payload invalido 422).
- Impacto: fila offline pode acumular payload sem chance real de sucesso ate correcao manual.
- Acao recomendada: diferenciar comportamento por status HTTP:
  - `200`: sucesso/duplicado
  - `422`: nao retry automatico
  - `401/403`: nao retry ate corrigir credencial/assinatura
  - `5xx`: retry/backoff + outbox

## 4) Decisoes conjuntas recomendadas (para formalizar)

1. Assinatura oficial: HMAC SHA-256 de `timestamp.rawBody`.
2. Header oficial: `X-PDV-Signature`, `X-PDV-Timestamp`, `X-Request-Id`.
3. Formato de assinatura: `sha256=<hex>`.
4. Versao de contrato: manter `agent.version` e adicionar `schema_version` no payload.
5. Timezone oficial: datetimes sempre com offset explicito.
6. Idempotencia backend: continuar por `sync_id` e `(store_pdv_id, id_operacao)`.
7. Idempotencia filhos: `line_no` como chave natural recomendada.

## 5) Plano de acao por responsavel

## 5.1 Time Hiper (agente)

- Implementar HMAC + timestamp + request id por tentativa.
- Adicionar timezone explicito em todos os datetimes.
- Adicionar `line_no` estavel em itens/pagamentos.
- Publicar JSON Schema versionado por release.
- Ajustar politica de retry por tipo de erro HTTP.

## 5.2 Time Backend (Laravel)

- Manter endpoint idempotente e processamento assincrono como ja implementado.
- Validar parsing temporal assumindo timezone explicito no payload.
- Continuar monitorando backlog, latencia e lojas sem sync.
- Rodar homologacao E2E com replay de outbox e janelas fora de ordem.
- Manter mapeamento operacional de lojas via comando `pdv:map-store`.

## 6) Checklist de homologacao E2E

1. Fluxo nominal 10/10 min por uma loja piloto.
2. Queda de rede com backlog e replay posterior.
3. Reenvio de payload duplicado (`sync_id` igual) retornando 200.
4. Syncs fora de ordem temporal sem quebra de consistencia.
5. Payload com vendedor null e warnings.
6. Payload com multipagamento e multiplos itens.

## 7) Criterio de pronto para go-live gradual

- Agente enviando HMAC em producao.
- Datetimes com timezone explicito.
- Contrato versionado e documentado.
- Homologacao aprovada nos 6 cenarios acima.
- Monitoramento operacional ativo no backend por loja/status.

## 8) Mapeamento de lojas aplicado no backend (2026-02-11)

Mapeamento operacional cadastrado:

- `pdv_store_id=10 -> store_id=1` (alias `tijucas-01`)
- `pdv_store_id=11 -> store_id=2`
- `pdv_store_id=12 -> store_id=3`
- `pdv_store_id=13 -> store_id=4`
- `pdv_store_id=14 -> store_id=5`
- `pdv_store_id=15 -> store_id=6`
- `pdv_store_id=16 -> store_id=7`
- `pdv_store_id=17 -> store_id=8`
- `pdv_store_id=18 -> store_id=9`
- `pdv_store_id=19 -> store_id=10`
- `pdv_store_id=20 -> store_id=11`
- `pdv_store_id=21 -> store_id=12`

Observacao:
- os mappings acima seguiram a convencao operacional observada na loja piloto (`001 -> 10`, offset `+9`).
- recomenda-se confirmacao final com o time Hiper antes do rollout completo.
