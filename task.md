# TASK - Pendencias Reais Webhook PDV (Somente o que falta)

Data: 2026-02-11
Escopo: estabilizar webhook `POST /api/v1/pdv/sync` em producao.

## 1) Estado atual validado agora

- `event_type` implantado e migration aplicada em producao (`2026_02_11_000240_add_event_type_to_pdv_syncs_table`).
- Teste real no endpoint:
  - `event_type=turno_closure` -> `201 created`
  - reenvio mesmo `sync_id` -> `200 duplicate`
- Resultado atual de processamento:
  - existem syncs recentes presos em `queued` (worker nao esta consumindo continuamente).

## 2) Prioridade P0 (bloqueante agora)

### PR-18 - Worker persistente de fila em producao
Objetivo: evitar acumulo de `queued` e garantir processamento continuo.

Subetapas:
- [ ] Garantir worker ativo 24/7 no Plesk Toolkit (ou cron fallback com `flock`).
- [ ] Confirmar worker consumindo fila `pdv,default` (nao apenas execucao manual pontual).
- [ ] Processar backlog atual (`sync_id` pendentes) ate `status=processed`.
- [ ] Validar com `pdv:queue-smoke --wait=20` sem abrir worker manual.

Criterio de aceite:
- `pdv_syncs` novos mudam de `queued` -> `processed` automaticamente em ate ~1 min.

---

### PR-19 - Scheduler recorrente no Plesk
Objetivo: manter heartbeat/retry/purge operando sem acao manual.

Subetapas:
- [ ] Confirmar tarefa recorrente `schedule:run` a cada 1 minuto no Toolkit.
- [ ] Validar recorrencia real do heartbeat (`pdv:scheduler:heartbeat`) por pelo menos 15 min.
- [ ] Validar execucao recorrente:
  - [ ] `pdv:retry-failed` a cada 10 min (quando habilitado);
  - [ ] `pdv:purge-raw-payloads` diario.

Criterio de aceite:
- `pdv:infra-check --json` fica estavel sem warning de scheduler em verificacoes sequenciais.

---

### PR-21 - Monitoramento minimo de operacao
Objetivo: detectar fila parada antes de impactar loja.

Subetapas:
- [x] Definir check operacional (a cada 10 min):
  - [x] backlog Redis `queues:pdv`;
  - [x] quantidade `pdv_syncs.status=queued` acima do limite;
  - [x] `failed_jobs` > 0.
- [x] Integrar alerta externo (Webhook/Slack/Email) para fila parada/backlog alto.
- [x] Criar runbook curto de resposta (reiniciar worker, validar consumo, retry).
- [ ] Configurar canais reais no `.env` de producao e validar disparo (alerta + recovery).

Criterio de aceite:
- incidente de fila parada gera alerta automatico em minutos.

## 3) Prioridade P1 (dados e governanca)

### PR-24 - Contrato final de identidade de loja
Objetivo: fechar chave canonica de loja com time ERP.

Subetapas:
- [ ] Confirmar formalmente se `id_ponto_venda` e globalmente unico.
- [ ] Definir politica para renomeacao/reuso.
- [ ] Receber carga oficial inicial de lojas.
- [ ] Formalizar onboarding de nova loja antes do primeiro sync.

---

### PR-25 - Contrato final de identidade de usuario
Objetivo: garantir metas/ranking corretos entre lojas.

Subetapas:
- [ ] Confirmar unicidade real de `id_usuario` (por loja vs global).
- [ ] Definir regra oficial para vendedor `null`.
- [ ] Confirmar politica de alteracoes retroativas.
- [ ] Receber carga inicial de usuarios por loja.

---

### PR-26 - Validacao final de mapping de usuario em producao
Objetivo: fechar ciclo operacional do `pdv_user_mappings`.

Subetapas:
- [ ] Testar caso real com usuario mapeado (`vendedor_user_id` preenchido).
- [ ] Testar caso real sem mapping (`risk_flag=user_mapping_missing`).
- [ ] Documentar rotina de manutencao de mappings (`pdv:map-user`).

---

### PR-28 - Mitigacao restante de colisao de loja
Objetivo: remover risco estrutural de colisao entre lojas.

Subetapas:
- [ ] Definir chave canonica temporaria operacional (`id_ponto_venda + alias`) no processo.
- [ ] Alinhar com ERP novo campo `store_external_id`.
- [ ] Planejar migracao de mapping para `store_external_id`.

---

### PR-27 - Politica de conflito e reconciliacao
Objetivo: padronizar tratamento para dados conflitantes.

Subetapas:
- [ ] Definir matriz de decisao (bloquear vs aceitar com `risk_flags`).
- [ ] Definir rotina diaria de reconciliacao com time ERP.
- [ ] Criar playbook de ajuste sem perda de rastreabilidade.

---

### PR-30 - Normalizacao de dicionarios
Objetivo: filtros globais consistentes entre lojas.

Subetapas:
- [ ] Formalizar chaves compostas:
  - [ ] usuario: (`store_pdv_id`, `pdv_user_id`)
  - [ ] finalizador: (`store_pdv_id`, `id_finalizador`)
- [ ] Definir `codigo_barras` como chave canonica de produto para visao global.
- [ ] Planejar sync periodico de dicionarios.

## 4) Prioridade P2 (resiliencia e evolucao)

### PR-29 - Estrategia para correcao retroativa
Objetivo: tratar cancelamentos/edicoes fora da janela de 10 min.

Subetapas:
- [ ] Formalizar reconciliacao periodica historica.
- [ ] Alinhar backlog PR-08 no agente (eventos retroativos).
- [ ] Definir sinalizacao no backend para dados potencialmente desatualizados.

---

### PR-22 - Retencao e limpeza
Objetivo: manter auditoria com custo controlado.

Subetapas:
- [ ] Confirmar politica final:
  - [ ] RAW: 30 dias
  - [ ] metadados: 12+ meses
- [ ] Validar periodicamente crescimento de storage/tabelas.

---

### PR-23 - Hardening de carga
Objetivo: validar margem de seguranca antes do rollout total.

Subetapas:
- [ ] Teste de carga com 15 lojas + retries/outbox.
- [ ] Ajustar batch/indices conforme telemetria.
- [ ] Consolidar playbook de incidente (Redis down, backlog alto, worker parado).

## 5) Ordem sugerida de execucao

1. PR-18
2. PR-19
3. PR-21
4. PR-24
5. PR-25
6. PR-26
7. PR-28
8. PR-27
9. PR-30
10. PR-29
11. PR-22
12. PR-23
