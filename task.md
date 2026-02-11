# TASK - Prioridades Redis/Fila + Webhook PDV v2.0

Data: 2026-02-11  
Escopo: estabilidade de ingestao `POST /api/v1/pdv/sync` em producao (Plesk + Redis).  
Fora de escopo: telas e dashboards de negocio.

## 1) Snapshot atual (confirmado)

Ja concluido no codigo:
- webhook idempotente (`sync_id`) com `201` novo e `200` duplicado;
- processamento assincrono por job unico + lock por loja;
- schema versionado (`schema_version`) + `X-Request-Id`;
- idempotencia de filhos com `line_id` (fallback `row_hash`);
- purge de RAW + retry controlado via scheduler (`routes/console.php`);
- observabilidade basica em `/api/v1/admin/pdv/syncs` e `/metrics`.

Infra reportada do servidor:
- Redis `5.0.3`, standalone, localhost-only (`127.0.0.1:6379`), nao exposto externo;
- sem senha (`requirepass` vazio), aceitavel no cenario local-only;
- `maxmemory` alto e politica `noeviction`;
- PHP CLI correto para Artisan: `/opt/plesk/php/8.2/bin/php`;
- Toolkit Plesk disponivel para worker e scheduler.

## 2) Gap real hoje (o que ainda pode quebrar producao)

1. Worker ainda precisa ficar persistente no Laravel Toolkit (nao apenas `queue:work` manual em terminal).
2. Scheduler ainda precisa ficar recorrente no Toolkit (nao apenas `schedule:run` manual).
3. Falta formalizar alerta externo (Slack/WhatsApp/email) para backlog/falhas.
4. Falta disciplina operacional de monitoramento continuo dos indicadores de fila.
5. Falta contrato definitivo de normalizacao de lojas (`id_ponto_venda`) com o time ERP.
6. Falta contrato definitivo de normalizacao de usuarios PDV (`operador.id_usuario` e `vendedor.id_usuario`) para metas e filtros.
7. Backend ainda nao possui tabela/comando oficial para mapping de usuario PDV -> usuario interno.
8. Falta regra operacional para conflitos de identidade (usuario null, troca de nome, correcao retroativa).
9. `id_ponto_venda` pode ser apenas local por banco (nao ha garantia de unicidade global na rede).
10. Agente nao reenvia correcao retroativa (cancelamento/troca de vendedor fora da janela).
11. `id_finalizador` e `id_produto` podem variar por loja; filtros globais exigem normalizacao composta.

## 3) Prioridades (atualizado)

- `P0` fechar contrato de normalizacao do JSON (lojas/usuarios) e manter fila/scheduler recorrentes.
- `P1` monitoramento/alertas, governanca de mapping e disciplina operacional.
- `P2` carga, tuning fino e hardening.

## 4) Backlog por prioridade

## P0 (bloqueante)

### PR-24 - Contrato de normalizacao de lojas PDV
Objetivo: remover ambiguidade de identidade de loja no webhook antes do rollout total.

Subetapas:
- [ ] Validar com time ERP se `store.id_ponto_venda` e chave canonica imutavel.
- [ ] Confirmar regra para renomeacao/reuso de loja e impacto no historico.
- [ ] Obter carga oficial inicial de lojas (id, nome, alias, status).
- [ ] Definir procedimento de onboarding de nova loja antes do primeiro sync.
- [ ] Registrar respostas na doc `docs/PERGUNTAS_NORMALIZACAO_LOJAS_USUARIOS_WEBHOOK_PDV.md`.

Criterio de aceite:
- existe definicao escrita de chave canonica de loja e processo de mudanca.

---

### PR-25 - Contrato de normalizacao de usuarios PDV
Objetivo: garantir consistencia de metas, ranking e filtros por vendedor.

Subetapas:
- [ ] Confirmar escopo de unicidade de `id_usuario` (global vs por loja).
- [ ] Confirmar se mesma pessoa pode ter IDs diferentes por loja.
- [ ] Confirmar regra de negocio para `id_usuario` null em item e operador.
- [ ] Confirmar comportamento de alteracao retroativa de vendedor/operador.
- [ ] Obter carga inicial oficial de usuarios por loja (id, nome, status, papel).

Criterio de aceite:
- regra de identidade de usuario fechada por escrito e aprovada entre times.

---

### PR-28 - Mitigacao de colisao de identidade de loja
Objetivo: evitar associacao de sync na loja errada quando `id_ponto_venda` nao for globalmente unico.

Subetapas:
- [ ] Definir chave canonica temporaria de loja: `id_ponto_venda + store.alias` (ate existir `store_external_id`).
- [x] Adicionar validacao de consistencia do mapping na ingestao (`alias` divergente -> `risk_flag` especifica).
- [x] Definir fluxo de bloqueio seguro para colisao detectada (`status=blocked` + alerta).
- [ ] Alinhar com time ERP proposta de novo campo `store_external_id` no payload.
- [ ] Planejar migracao de mapping para `store_external_id` (quando disponivel).

Criterio de aceite:
- colisao de loja nao gera upsert silencioso em loja incorreta.

---

### PR-26 - Implementar normalizacao de usuario no backend
Objetivo: sair de "armazenar id bruto" para mapping operacional de usuario PDV -> usuario interno.

Subetapas:
- [x] Criar migration `pdv_user_mappings` (`store_pdv_id`, `pdv_user_id`, `user_id`, `active`, `source`, `confidence`).
- [x] Criar comando `pdv:map-user` para operacao manual/auditavel.
- [x] Aplicar mapping no processamento (turnos/itens) sem quebrar ingestao.
- [x] Marcar `risk_flags` quando usuario PDV vier sem mapping (`user_mapping_missing`).
- [x] Expor informacao no admin (`/api/v1/admin/pdv/syncs`) para monitoramento.
- [ ] Executar migrations em homolog/producao e validar ingestao real com usuario mapeado e nao mapeado.

Criterio de aceite:
- sync processa normalmente, mas sinaliza explicitamente usuarios nao mapeados.

---

### PR-31 - Suporte a `event_type` (PR-09 agente)
Objetivo: receber fechamento de caixa mesmo sem vendas na janela (`turno_closure`) sem quebrar compatibilidade.

Subetapas:
- [x] Aceitar `event_type` no webhook com fallback seguro para `sales` quando vier desconhecido.
- [x] Persistir `event_type` em `pdv_syncs` para auditoria e monitoramento.
- [x] Aceitar payload com `vendas=[]` e `ops.count=0` quando `event_type=turno_closure`.
- [x] Atualizar schema `docs/schema_v2.0.json` para incluir `event_type`.
- [x] Expor `event_type` em `/api/v1/admin/pdv/syncs` e incluir `by_event_type` nas métricas.
- [x] Cobrir cenários com testes (sales, turno_closure, fallback de `event_type`).
- [ ] Executar migration em homolog/producao e validar ingestao real com payload `turno_closure`.

Criterio de aceite:
- backend recebe e processa fechamento de turno sem exigir vendas na janela.

---

### PR-17 - Ativar baseline Redis em producao
Objetivo: tirar o webhook do modo dependente de `sync` e garantir processamento desacoplado.

Subetapas:
- [x] Atualizar `.env.example` com estrutura recomendada Redis:
  - `REDIS_DB=0`, `REDIS_CACHE_DB=1`, `REDIS_PREFIX=mc:api:`;
  - `REDIS_QUEUE_RETRY_AFTER=300`, `REDIS_QUEUE_BLOCK_FOR=5`.
- [x] Parametrizar `block_for` no `config/queue.php` (`REDIS_QUEUE_BLOCK_FOR`).
- [x] Definir `.env` de producao:
  - `QUEUE_CONNECTION=redis`;
  - `CACHE_STORE=redis`;
  - `REDIS_HOST=127.0.0.1`, `REDIS_PORT=6379`.
- [x] Rodar no servidor:
  - `/opt/plesk/php/8.2/bin/php artisan config:clear`
  - `/opt/plesk/php/8.2/bin/php artisan cache:clear`
  - validacao com `/opt/plesk/php/8.3/bin/php artisan pdv:infra-check --json` (`ok=true`).

Criterio de aceite:
- sync novo gera job em Redis e nao executa inline no request HTTP.

---

### PR-18 - Worker oficial no Laravel Toolkit (Plesk)
Objetivo: manter consumo continuo da fila sem gambiarra de nohup/screen.

Subetapas:
- [ ] Ativar "Fila" no Laravel Toolkit.
- [ ] Configurar worker inicial:
  - `stop-when-empty`: desabilitado;
  - `timeout`: `120` ou `180`;
  - `max-jobs`: `500`;
  - `max-time`: `3600`.
- [ ] Garantir reinicio pos deploy:
  - painel Toolkit, ou
  - `/opt/plesk/php/8.2/bin/php artisan queue:restart`.
- [x] Criar comando de smoke test de worker: `pdv:queue-smoke --wait=20`.
- [x] Validar consumo da fila `pdv` em tempo real durante ingestao com `pdv:queue-smoke` (execucao manual `queue:work` validada em producao).
- [ ] Validar consumo em modo persistente do Toolkit (sem terminal SSH aberto).

Evidencia de producao (2026-02-11):
- com worker manual ativo (`queue:work redis --queue=pdv,default ...`): `pdv:queue-smoke --wait=20` consumiu com sucesso.
- sem worker ativo: `pdv:queue-smoke --wait=20` expirou em 20s (comportamento esperado).

Criterio de aceite:
- fila `pdv` sem backlog crescente em operacao nominal (15 lojas / 10 min).

---

### PR-19 - Scheduler de producao no Toolkit
Objetivo: garantir automacoes (`purge`/`retry`) sem dependencias manuais.

Subetapas:
- [ ] Criar tarefa agendada no Toolkit (cada 1 minuto):
  - `/opt/plesk/php/8.2/bin/php /var/www/vhosts/maiscapinhas.com.br/api.maiscapinhas.com.br/artisan schedule:run`
- [x] Validar execucao manual dos jobs agendados:
  - `pdv.scheduler.heartbeat` executando via `schedule:run`.
- [x] Validar `pdv:infra-check --json` sem warnings apos heartbeat manual (`warnings=0`).
- [ ] Validar execucao recorrente dos jobs agendados (Toolkit):
  - `pdv:purge-raw-payloads` diario;
  - `pdv:retry-failed` a cada 10 min quando habilitado.
- [ ] Confirmar timezone/clock do servidor para consistencia dos horarios.

Criterio de aceite:
- comandos de manutencao executam automaticamente sem intervencao manual.

---

### PR-20 - Seguranca operacional de fila (timeout x retry_after)
Objetivo: evitar redelivery indevido e duplicacao por timeout mal ajustado.

Subetapas:
- [x] Definir regra final:
  - `REDIS_QUEUE_RETRY_AFTER` > timeout max do worker.
- [x] Consolidar baseline sugerido em config/env de referencia:
  - timeout worker `180`;
  - `REDIS_QUEUE_RETRY_AFTER=300`.
- [x] Revisar `tries/backoff` dos jobs PDV:
  - `ProcessPdvSyncJob` agora usa config/env (`PDV_JOB_TRIES`, `PDV_JOB_BACKOFF_SECONDS`).
- [x] Registrar decisao em doc operacional para nao regredir em futuros deploys.

Criterio de aceite:
- job longo nao e reentregue enquanto ainda esta processando.

## P1 (alta)

### PR-27 - Politica de conflito e reconciliacao de identidade
Objetivo: padronizar resposta operacional para dados contraditorios de loja/usuario.

Subetapas:
- [ ] Definir matriz de decisao para conflitos:
  - mesmo `pdv_user_id` com nomes diferentes;
  - mesmo nome com `pdv_user_id` diferente;
  - loja sem mapping;
  - usuario sem mapping.
- [ ] Definir quando bloquear (`status=blocked`) vs aceitar com `risk_flags`.
- [ ] Definir rotina diaria de reconciliacao de mappings com time ERP.
- [ ] Criar playbook de ajuste de mapping sem reprocessamento destrutivo.

Criterio de aceite:
- conflitos recorrentes tem tratamento padrao e auditavel.

---

### PR-29 - Estrategia para correcao retroativa (cancelamentos/edicoes)
Objetivo: reduzir risco de divergencia historica quando ERP altera dados fora da janela de 10 min.

Subetapas:
- [ ] Formalizar que webhook incremental nao corrige historico automaticamente.
- [ ] Definir reconciliacao periodica (ex.: diario) para detectar cancelamentos pos-envio.
- [ ] Criar backlog tecnico do agente para evento de cancelamento retroativo (PR-08 no lado ERP).
- [ ] Definir sinalizacao no backend para dados potencialmente desatualizados.
- [ ] Criar runbook de ajuste operacional quando divergencia for detectada.

Criterio de aceite:
- existe processo definido para tratar divergencia retroativa sem perda de rastreabilidade.

---

### PR-30 - Normalizacao de dicionarios (usuarios/finalizadores/produtos)
Objetivo: garantir filtros corretos entre lojas com cadastros locais.

Subetapas:
- [ ] Definir chave de usuario como composta (`store_pdv_id`, `pdv_user_id`).
- [ ] Definir chave de pagamento como composta (`store_pdv_id`, `id_finalizador`).
- [ ] Definir chave canonica de produto para visao global (`codigo_barras` preferencial).
- [ ] Solicitar carga inicial de usuarios por loja ao time ERP.
- [ ] Planejar sync periodico de dicionarios para reconciliacao.

Criterio de aceite:
- filtros e relatorios interlojas nao dependem de IDs locais isolados.

---

### PR-21 - Observabilidade de fila e incidentes
Objetivo: detectar ruptura em minutos.

Subetapas:
- [x] Definir rotina operacional com comandos:
  - `queue:failed`, `queue:retry all`, `queue:flush`.
- [x] Criar comando de prontidao `pdv:infra-check` (Redis/queue/cache/scheduler/backlog).
- [x] Adicionar heartbeat de scheduler em cache (`pdv:scheduler:heartbeat`) para deteccao de parada.
- [x] Validar gate operacional completo (`pdv:infra-check --json` com `ok=true`, `errors=0`, `warnings=0`).
- [ ] Monitorar indicadores minimos:
  - backlog por fila;
  - `failed_jobs` por janela;
  - lojas sem sync > 20 min.
- [ ] Integrar alerta externo (Slack/WhatsApp/email).

Criterio de aceite:
- alerta dispara antes de gerar perda operacional perceptivel.

---

### PR-22 - Politica de retencao e limpeza
Objetivo: manter rastreabilidade sem crescer custo/risco.

Subetapas:
- [ ] Confirmar regra final:
  - `pdv_sync_payloads`: 30 dias;
  - `pdv_syncs` (metadados): 12+ meses.
- [ ] Revisar se o purge remove somente RAW e preserva metadados.
- [ ] Adicionar check mensal de tamanho de tabelas e storage.

Criterio de aceite:
- retencao consistente e auditavel sem crescimento descontrolado.

## P2 (media)

### PR-23 - Hardening de carga e resiliencia
Objetivo: validar margem de seguranca antes de escalar tudo.

Subetapas:
- [ ] Teste de carga com retries/outbox (15 lojas).
- [ ] Ajustar batch de upsert conforme telemetria real.
- [ ] Revisar indices de maior custo em consultas/admin.
- [ ] Documentar playbook de incidente:
  - worker parado;
  - Redis indisponivel;
  - backlog alto.

Criterio de aceite:
- sem perda/duplicacao em carga prolongada e com falhas transitorias.

## 5) Ordem recomendada de execucao (faltantes)

1. PR-24
2. PR-25
3. PR-28
4. PR-26
5. PR-18
6. PR-19
7. PR-27
8. PR-30
9. PR-29
10. PR-21
11. PR-22
12. PR-23

## 6) Definicao de pronto (infra webhook PDV)

Pronto quando:
- webhook responde rapido e sempre enfileira via Redis;
- worker + scheduler estao ativos no Toolkit;
- identidade de loja e usuario PDV esta fechada por contrato;
- mappings de loja e usuario estao operacionais e monitorados;
- risco de colisao de `id_ponto_venda` esta mitigado;
- estrategia para correcao retroativa esta definida com o time ERP;
- timeout/retry_after estao alinhados e documentados;
- backlog/falhas sao monitorados com alerta;
- retencao RAW 30 dias e metadados 12+ meses estao operando.
