# Perguntas ao Time Python - Webhook PDV v3.1 (Pre-Deploy)

Data: 2026-02-13  
Projeto: `maiscapinhas-erp-api`  
Destino: Time PDV Sync Agent (Python)  
Referencia: `docs/VALIDACAO_JSON_PDV_V3_1_PRE_DEPLOY_2026-02-13.md`

---

## 1) Objetivo desta rodada

Fechar as ultimas duvidas de contrato e logica do payload para subir em producao sem regressao.

Foco:
- estrutura JSON (v3.0/v3.1),
- invariantes de negocio,
- campos novos (`cnpj`, `login`),
- consistencia de turnos/vendas/snapshots.

---

## 2) Contexto observado nos payloads reais

- Recebemos payload com `schema_version=3.0` contendo campos de v3.1 (`store.cnpj`, `*.login`).
- Recebemos `turnos[].duracao_minutos = null` em varios turnos abertos.
- Em uma amostra de `turno_closure`, veio lista grande de turnos abertos antigos (2024/2025/2026).
- Em uma amostra da loja Mata Atlantica (`store.id_ponto_venda=9`), apareceu turno com operador de outra loja (login `filial12`).

Precisamos confirmar o que e esperado vs o que e bug.

---

## 3) Perguntas P0 (bloqueantes para go-live)

### P0.1 - Versao efetiva do contrato

Perguntas:
- A partir de agora o envio oficial sera **sempre** `schema_version=3.1`?
- O header `X-PDV-Schema-Version` sera sempre igual ao body (`3.1`)?
- Existe qualquer cenario em que voces ainda enviarao payload com corpo 3.0 + campos 3.1?

### P0.2 - Nulabilidade de `duracao_minutos`

Perguntas:
- Regra oficial: em turno aberto `duracao_minutos` deve vir `null`, ou deve ser omitido?
- Essa regra vale igualmente para `turnos[]` e `snapshot_turnos[]`?

### P0.3 - Escopo da lista `turnos[]`

Perguntas:
- `turnos[]` deve trazer apenas turnos da loja do payload, ou pode trazer turnos de outros contextos?
- E esperado enviar varios turnos antigos abertos (anos anteriores) em todo ciclo?
- Existe limite oficial de quantidade de turnos no array principal?

### P0.4 - Coerencia loja x operador

Perguntas:
- Em payload de uma loja, e permitido aparecer operador de outra loja?
- O caso observado (`store` Mata Atlantica com operador `filial12`) e comportamento esperado ou bug de query?

### P0.5 - Invariante `window.minutes`

Perguntas:
- `window.minutes` deve sempre refletir `window.to - window.from`?
- Em nossas amostras houve janela de ~20 min com `minutes=10`; isso e esperado?

---

## 4) Perguntas P1 (qualidade de dados e relatorio)

### P1.1 - Autoridade entre `turnos[]` e `snapshot_turnos[]`

Perguntas:
- Quando houver divergencia no mesmo `id_turno` (ex.: `qtd_vendas`, `duracao_minutos`), qual bloco e fonte de verdade?
- Devemos sempre considerar snapshot como valor final?

### P1.2 - Diferenca de `duracao_minutos` entre detalhe e snapshot

Perguntas:
- Observamos casos `241` no `turnos[]` e `242` no `snapshot_turnos[]` para o mesmo turno.
- Regra de arredondamento oficial e `floor`, `ceil` ou `round`?

### P1.3 - `qtd_vendas` divergente no mesmo turno

Perguntas:
- Observamos `turnos[].qtd_vendas=2` e `snapshot_turnos[].qtd_vendas=4` no mesmo `id_turno`.
- Isso e esperado por timing/recalculo ou indica inconsistencia de consulta?

### P1.4 - Presenca de `login` nos snapshots

Perguntas:
- Em v3.1, `snapshot_turnos[].operador.login`, `snapshot_turnos[].responsavel.login` e `snapshot_vendas[].vendedor.login` podem vir `null`?
- Quando vier `null`, existe fallback oficial (ex.: resolver por `id_usuario`)?

### P1.5 - Unicidade e normalizacao de `login`

Perguntas:
- `login` e unico por tenant inteiro ou apenas por loja?
- Comparacao deve ser case-insensitive?
- Existe possibilidade de login mudar historicamente para o mesmo usuario?

### P1.6 - Invariante entre `ops` e `vendas`

Perguntas:
- Regra oficial continua:
  - `ops.count >= vendas HIPER_CAIXA`
  - `ops.loja_count >= vendas HIPER_LOJA`
?
- Existe outra excecao alem do caso "operacao com todos itens cancelados"?

---

## 5) Perguntas P2 (operacao e evolucao)

### P2.1 - Volume e backlog

Perguntas:
- Em backlog longo ainda sera 1 payload unico sem chunking?
- Qual faixa real esperada de tamanho por payload (KB/MB) em 2h, 6h e 24h offline?

### P2.2 - Taxonomia de warnings

Perguntas:
- Podem compartilhar catalogo fechado de warnings atuais (prefixos oficiais)?
- Para v3.1/v3.2, voces confirmam migracao para formato estruturado (`code`, `message`)?

### P2.3 - Politica de release de contrato

Perguntas:
- Qual fluxo oficial para novo campo com `additionalProperties=false`?
- Voces conseguem manter aviso minimo de 7 dias para breaking changes de schema?

---

## 6) Formato sugerido para resposta

Para cada item:
- `ID`
- `Resposta curta`
- `Regra final`
- `Exemplo real de payload`
- `Impacto para backend`
- `Prazo` (se houver mudanca no agente)

---

## 7) Amostras que pedimos para regressao (anexar quando responder)

1. `sales` com turno aberto (`duracao_minutos=null`)  
2. `turno_closure` sem vendas e sem ruido de turnos antigos  
3. `mixed` com caixa+loja e colisao de ids entre canais  
4. caso com `GESTAO_DB_FAILURE`  
5. caso com `login` ausente/null em snapshot  
6. caso backlog longo (>6h)

