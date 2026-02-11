# Analise Tecnica Profunda - Integracao Webhook PDV Sync Agent v2.0

Data da analise: 2026-02-11  
Projeto: `maiscapinhas-erp-api` (Laravel 12)

## 1) Resposta direta (o que voce perguntou)

### 1.1 Existem endpoints de fechamento de caixa hoje?
Sim.

- `cash_shifts`: existe como rotas em `/api/v1/cash/shifts` (listar, criar, pendentes, divergentes, detalhe). Referencias: `app/Modules/ConferenciaCaixa/routes.php:17-35`.
- `cash_closings`: existe como rotas em `/api/v1/cash/closings/{shift}` (show, store, update, submit, approve, reject). Referencias: `app/Modules/ConferenciaCaixa/routes.php:42-64`.
- `cash_closing` (singular): **nao existe rota singular**.

Confirmado tambem em `php artisan route:list --path=api/v1/cash` (11 rotas).

### 1.2 Existe endpoint hoje para receber webhook PDV Sync (`/webhook/pdv-sync` ou `/api/v1/pdv/sync`)?
Sim.

- Endpoint implementado: `POST /api/v1/pdv/sync`.
- Middlewares aplicados: `pdv.signature` (HMAC) + `throttle:pdv`.
- O endpoint esta em rotas publicas de `api_v1` (nao exige Sanctum).

### 1.3 Existe estrutura atual para anti-duplicacao por `sync_id`/`id_operacao`?
Parcialmente sim.

- `sync_id`: implementado em `pdv_syncs.sync_id` com `UNIQUE`.
- `id_operacao`: implementado em `pdv_vendas` com `UNIQUE (store_pdv_id, id_operacao)`.
- `id_turno`: implementado em `pdv_turnos` com `UNIQUE (store_pdv_id, id_turno)`.
- Filhos idempotentes:
  - `pdv_venda_itens` com `UNIQUE (store_pdv_id, id_operacao, line_no)`;
  - `pdv_venda_pagamentos` com `UNIQUE (store_pdv_id, id_operacao, line_no)`.

## 2) O que temos hoje na API (diagnostico real)

## 2.1 Arquitetura de rotas e seguranca atual

- As rotas de caixa estao dentro de grupo autenticado com Sanctum e permissao (`auth:sanctum` + `permission`). Referencias:
  - Grupo autenticado: `routes/api_v1.php:83`
  - Inclusao modulo caixa: `routes/api_v1.php:154-155`
  - Middlewares por rota de caixa: validado via `route:list -v`.
- O fluxo atual de caixa foi desenhado para interacao de usuario interno (vendedor/conferente), nao para ingestao maquina-a-maquina.

## 2.2 Modelo de dados atual de caixa

### Tabelas existentes

- `cash_shifts` (`database/migrations/2026_01_07_154619_create_cash_shifts_table.php:10`)
  - Campos principais: `store_id`, `date`, `shift_code`, `seller_id`, `status`.
  - Status possiveis: `open`, `closed`, `pending`.
  - Unicidade por loja/data/turno/vendedor adicionada depois (`2026_01_07_203900...`: `store_id + date + shift_code + seller_id`).
- `cash_closings` (`database/migrations/2026_01_07_154621_create_cash_closings_table.php:10`)
  - 1:1 com `cash_shifts` (campo `cash_shift_id` unico).
  - Status: `draft`, `submitted`, `approved`, `rejected`.
  - Campos de justificativa no nivel do fechamento: `justification_text`, `justified` (`2026_01_09_211727...:19-20`).
- `cash_closing_lines` (`database/migrations/2026_01_07_154622_create_cash_closing_lines_table.php:10`)
  - Campos: `label`, `system_value`, `real_value`, `diff_value`, `justification_text`.

### Fluxo de negocio atual

- Workflow: `draft -> submitted -> approved/rejected` (controller + service).
  - Controller: `app/Http/Controllers/Api/V1/CashClosingController.php`.
  - Regras de transicao: `app/Models/CashClosing.php:113-123`.
  - Persistencia transacional com `lockForUpdate`: `app/Services/CashClosingService.php:24-225`.

## 2.3 Modelo de dados atual de vendas

### Tabela existente

- `sales` (`database/migrations/2026_01_07_154614_create_sales_table.php:10`)
  - Campos: `store_id`, `seller_id`, `sold_at`, `amount`, `source`.
  - **Nao ha** `id_operacao`, `id_turno`, itens de venda, pagamentos, nem dedupe externo.
- Modelo `Sale`: `app/Models/Sale.php:16-20`.
- API de vendas: `app/Http/Controllers/Api/V1/SaleController.php`.
  - Filtros atuais: `store_id`, `seller_id`, `from`, `to`.
  - Nao existe filtro nativo por produto, meio de pagamento, `id_turno`, `id_operacao`.

## 2.4 Modulos que dependem fortemente de `sales` e `cash_*`

- Dashboards: `app/Http/Controllers/Api/V1/DashboardController.php`.
- Ranking: `app/Domains/Reports/Services/RankingService.php`.
- Performance de loja: `app/Domains/Reports/Services/StorePerformanceService.php`.
- Bonus/comissao: `app/Domains/Finance/Engines/BonusEngineService.php`, `app/Domains/Finance/Engines/CommissionEngineService.php`.

Todos estes modulos hoje agregam dados da tabela `sales` (valor consolidado por venda) + status de `cash_shifts/cash_closings`.

## 2.5 Jobs e recalculo automatico

- Observer de `Sale` dispara recalculo diario e mensal (`SaleObserver`): `app/Observers/SaleObserver.php:44-55`.
- Observer de `CashClosing` tambem dispara recalculo: `app/Observers/CashClosingObserver.php:34-47`.
- Isso significa que importacao massiva via webhook em `sales` pode gerar tempestade de jobs se nao houver estrategia de batch/coalescencia.

## 2.6 Infra de sync no projeto (o que existe e pode ser reaproveitado)

Existe um exemplo de sincronizacao externa em People Analytics:

- Command: `people:sync-kpis` (`app/Console/Commands/SyncPeopleKpisCommand.php:13`).
- Service com `updateOrCreate`: `app/Domains/Analytics/Services/PeopleAnalyticsSyncService.php:64`.

Pontos importantes:

- **Nao existe agendamento configurado** em `routes/console.php` (so comando `inspire` em `routes/console.php:6`).
- Portanto, tambem nao existe scheduler pronto para controle de "janela de 10 min".

## 3) Gap completo: payload PDV v2.0 x estrutura atual

| Payload PDV v2.0 | Cobertura atual | Gap |
|---|---|---|
| `integrity.sync_id` | Nao existe | Falta idempotencia de sync |
| `ops.ids` (`id_operacao`) | Nao existe | Falta anti-duplicacao por operacao |
| `store.id_ponto_venda` | Nao existe campo externo em `stores` | Falta mapeamento loja ERP x PDV |
| `turnos[].id_turno` GUID | Nao existe em `cash_shifts` | Falta chave externa de turno |
| `turnos[].sequencial` | Parcial (`shift_code`) | Falta estrategia de mapeamento (1/2/3 vs M/T/N + GUID) |
| `turnos[].operador.id_usuario` | Nao existe mapeamento externo em `users` | Falta mapeamento operador PDV x usuario interno |
| `turnos[].totais_sistema` | Parcial (`cash_closing_lines.system_value`) | Falta ingestao automatica e historico por sync |
| `turnos[].fechamento_declarado` | Parcial (`real_value`) | Falta regras de atualizacao por janela e fechamento |
| `turnos[].falta_caixa` | Parcial (`diff_value`) | Falta padrao de persistencia por meio/tipo |
| `vendas[].id_operacao` | Nao existe em `sales` | Sem dedupe por venda externa |
| `vendas[].itens[]` | Nao existe tabela | Sem suporte a produto/filtro item |
| `vendas[].pagamentos[]` | Nao existe tabela de pagamentos de venda | Sem suporte a filtro por meio/parcela/troco |
| `resumo.by_vendor/by_payment` | Parcial (pode ser calculado de `sales`) | Sem granularidade para recalculo confiavel |
| `warnings` | Nao existe persistencia | Sem rastreabilidade de qualidade de dados |

## 4) Principais lacunas estruturais para receber webhook a cada 10 min

## 4.1 Idempotencia e reprocessamento

Hoje nao ha:

- tabela de sync recebida;
- controle de `sync_id`;
- controle de `id_operacao` por loja;
- armazenamento bruto do payload para replay/auditoria.

Sem isso, qualquer retry do agente pode duplicar dados financeiros.

## 4.2 Granularidade insuficiente da tabela `sales`

A tabela `sales` so guarda valor total por venda e vendedor.

Para os modulos novos citados (filtros por produto/meio/turno/vendedor, conferencia detalhada), faltam estruturas de:

- item de venda;
- pagamento por venda;
- vinculo de venda com turno externo (`id_turno`);
- chave externa de operacao (`id_operacao`).

## 4.3 Mapeamentos externos faltantes

Nao existe mapeamento formal para:

- loja PDV (`id_ponto_venda`) -> `stores.id`;
- operador/vendedor PDV (`id_usuario`) -> `users.id`;
- finalizador PDV (`id_finalizador`) -> `payment_methods.id`.

## 4.4 Fluxo manual de caixa vs fluxo automatico de PDV

O modulo de caixa atual e orientado a input humano, nao a ingestao ciclica automatica.

Precisa definir regra de conciliacao entre:

- fechamento manual do vendedor no ERP;
- fechamento vindo do PDV via webhook;
- aprovacao do conferente.

## 4.5 Consistencias internas ja existentes (debt)

Foram detectadas inconsistencias que impactam confiabilidade para escalar:

1. Divergencia: parte do sistema usa justificativa por fechamento (`cash_closings.justified`), outra parte usa justificativa por linha (`cash_closing_lines.justification_text`).
   - Nivel fechamento: `app/Models/CashClosing.php:144-146`, `BonusEngineService.php:128-130`
   - Nivel linha: `CashShiftController.php:374`, `CashIntegrityService.php:62-65`
2. `FinanceController` referencia campos que nao existem nos models (`bonus_value`, `goal_value`, `total_sold`, `commission_value` etc). Referencia: `app/Http/Controllers/Api/V1/FinanceController.php:181-251` vs models/migrations `seller_daily_bonus` e `seller_monthly_commissions`.
3. Documentacao de dashboard esta divergente das rotas reais (`/dashboard/seller` vs `/dashboard/vendedor`).
   - Docs: `README.md:256-258`, `API_REFERENCE.md:323-325`
   - Rotas reais: `routes/api_v1.php:209-211`

## 5) Estrutura recomendada para receber o webhook (alvo)

## 5.1 Camada de ingestao dedicada (recomendada)

Criar modulo/namespace novo (ex: `PdvSync`) com rota publica autenticada por assinatura:

- `POST /api/v1/pdv/sync` (ou `/webhook/pdv-sync`)
- Header sugerido:
  - `X-PDV-Signature` (HMAC SHA-256 do body)
  - `X-PDV-Store` (opcional)
  - `X-Request-Id` (ja suportado pelo middleware)

Regra: sempre retornar `200` para payload duplicado (idempotencia), sem reinserir.

## 5.2 Persistencia em 2 camadas

### Camada A: raw + idempotencia

Criar tabelas de ingestao:

- `pdv_syncs` (sync_id unico, store externo, janela, status, warnings, payload_hash)
- `pdv_sync_payloads` (payload bruto JSON para auditoria/replay)

### Camada B: dados normalizados de dominio PDV

Criar tabelas:

- `pdv_turnos` (unique `id_turno`)
- `pdv_turno_pagamentos` (`id_turno + tipo + id_finalizador`)
- `pdv_vendas` (unique `store_id + id_operacao`)
- `pdv_venda_itens`
- `pdv_venda_pagamentos`

Sugestao: o seu schema proposto no briefing esta correto como base e deve ser adaptado para migrations Laravel + banco em uso.

## 5.3 Tabelas de mapeamento (obrigatorias)

Criar:

- `pdv_store_mappings` (`pdv_store_id`, `store_id`, `alias`, unique)
- `pdv_user_mappings` (`pdv_user_id`, `user_id`, opcionalmente `store_id`)
- `pdv_payment_method_mappings` (`pdv_finalizer_id`, `payment_method_id`)

Sem esses mapeamentos, filtros por vendedor/loja/meio ficam inconsistentes.

## 5.4 Projecao para modulos atuais (compatibilidade)

Depois de persistir `pdv_*`, projetar para tabelas existentes:

- `sales`: alimentar agregacao por vendedor com chave externa (`id_operacao`) para evitar duplicacao.
- `cash_shifts`: criar/atualizar turno interno ligado ao `id_turno` externo.
- `cash_closings`/`cash_closing_lines`: preencher sistema/declarado/diff quando `fechado=true`.

Importante: definir status inicial de fechamento projetado:

- opcao A: `submitted` automatico (conferente ainda aprova).
- opcao B: `approved` automatico (elimina etapa humana).

Para controle e auditoria, opcao A e mais segura.

## 5.5 Endpoints de consulta recomendados para frontend novo

Manter os atuais e adicionar endpoints dedicados a dados PDV:

- `GET /api/v1/pdv/turnos`
- `GET /api/v1/pdv/turnos/{id}/conferencia`
- `GET /api/v1/pdv/vendas` (filtros completos)
- `GET /api/v1/pdv/vendedores/ranking`
- `GET /api/v1/pdv/dashboard`

Filtros alvo:

- loja, periodo, turno, vendedor, produto, meio de pagamento, status de fechamento.

## 6) Plano de implementacao em fases (pratico)

## Fase 0 - Decisoes de negocio e contrato (curta)

- Fechar regras de mapeamento (`id_ponto_venda`, `id_usuario`, `id_finalizador`).
- Definir politica de fechamento automatico (`submitted` vs `approved`).
- Definir timezone oficial do payload (sugestao: UTC no transporte + conversao local).

## Fase 1 - Fundacao de ingestao

- Criar migrations `pdv_syncs`, `pdv_sync_payloads`, `pdv_turnos`, `pdv_turno_pagamentos`, `pdv_vendas`, `pdv_venda_itens`, `pdv_venda_pagamentos`.
- Criar mapeamentos (`pdv_store_mappings`, `pdv_user_mappings`, `pdv_payment_method_mappings`).
- Criar indices para consultas de periodo e filtros.

## Fase 2 - Endpoint webhook + seguranca

- Criar `PdvSyncController@ingest` com `FormRequest` de validacao forte.
- Validar assinatura HMAC.
- Implementar idempotencia:
  1. valida `sync_id` unico;
  2. valida `id_operacao` unico por loja.
- Armazenar payload bruto.

## Fase 3 - Processamento e projecao

- UPSERT de turnos e pagamentos por tipo (`sistema`, `declarado`, `falta`).
- INSERT idempotente de vendas/itens/pagamentos.
- Projecao para `sales` e `cash_*` com transacao e lock quando necessario.
- Coalescer recalculo financeiro (evitar 2 jobs por venda individual em lote).

## Fase 4 - Consultas e filtros para modulos novos

- Implementar endpoints `pdv/*` para frontend.
- Adicionar filtros por produto/pagamento/turno/vendedor.
- Garantir paginação, ordenacao e limites.

## Fase 5 - Testes e validacao de carga

Testes minimos:

- Payload valido processa 1x.
- Mesmo payload (mesmo `sync_id`) retorna 200 e nao duplica.
- Overlap parcial de `ops.ids` insere apenas novos.
- Falha de mapeamento (loja/vendedor/pagamento) gera warning controlado.
- Reprocessamento apos falha parcial.
- Carga simulada de 15 lojas x 10 min.

## Fase 6 - Operacao e observabilidade

- Dashboard de saude do sync por loja (`ultima_sync_at`, latencia, falhas).
- Alertas: loja sem sync > 20 min; crescimento de warnings; duplicidade.
- Runbook de replay por `sync_id`/janela.

## 7) O que precisa ser feito para viabilizar os modulos citados

## 7.1 Fechamento de caixa

- Persistir `turnos` + `totais_sistema` + `fechamento_declarado` + `falta_caixa` por meio de pagamento.
- Projetar para `cash_shifts`/`cash_closings` com regra clara de status.
- Unificar regra de justificativa (linha vs fechamento).

## 7.2 Metas de vendedores

- Garantir vendas confiaveis por vendedor (mapeamento `id_usuario` externo).
- Definir regra para venda com multiplos vendedores no mesmo cupom.
- Garantir que a projecao para `sales` nao distorca `sale_count` e `amount`.

## 7.3 Vendas com filtros avancados

- Criar fontes granulares (`pdv_venda_itens`, `pdv_venda_pagamentos`).
- Criar endpoints com filtros por produto/meio/parcela/troco/turno.
- Manter `sales` atual para compatibilidade, mas usar `pdv_*` para analytics avancado.

## 8) Checklist executivo (100% do necessario)

- [ ] Definir contrato de seguranca do webhook (HMAC + replay window).
- [ ] Criar schema `pdv_*` + mapeamentos externos.
- [ ] Implementar endpoint `POST /api/v1/pdv/sync` idempotente.
- [ ] Implementar UPSERT de turnos e dedupe de vendas por `id_operacao`.
- [ ] Implementar projecao controlada para `sales` e `cash_*`.
- [ ] Ajustar observers/recalculos para processamento em lote.
- [ ] Criar endpoints de consulta `pdv/*` com filtros novos.
- [ ] Corrigir inconsistencias atuais de finance/dashboard antes de acoplar alto volume.
- [ ] Cobrir com testes de idempotencia, reprocessamento e carga.
- [ ] Criar monitoramento operacional de sync por loja.

## 9) Conclusao tecnica

A API atual tem base solida de caixa/metas/ranking, mas esta preparada para operacao manual interna, nao para ingestao robusta de webhook PDV em janela fixa de 10 minutos.  
Para suportar com seguranca e escala, e necessario introduzir uma camada dedicada de integracao PDV (idempotencia + dados granulares + mapeamentos), e depois projetar esses dados para os modulos atuais sem quebrar o fluxo existente.


## 10) Validacao do blueprint de performance (atualizacao 2026-02-10)

## 10.1 Veredito

As diretrizes propostas fazem sentido e estao alinhadas com o melhor desenho para este cenario (15 lojas, envio a cada 10 min, retry/outbox, janela acumulada por turno).  
Recomendacao final: **adotar o blueprint com alguns ajustes de implementacao** para evitar gargalos e inconsistencias.

## 10.2 Avaliacao ponto a ponto

| Item proposto | Avaliacao | Ajuste recomendado no projeto |
|---|---|---|
| Ack rapido do webhook | Correto | Ingestao deve salvar minimo necessario e retornar rapido (ms) |
| RAW + idempotencia no banco | Correto e essencial | Constraint unica em `sync_id` e dedupe por `store_id + id_operacao` |
| Processamento assinc via queue | Correto | Nao processar payload pesado no request HTTP |
| Job unico por sync (`ShouldBeUniqueUntilProcessing`) | Correto | Evita processamento paralelo do mesmo `sync_id` |
| Lock por loja/turno | Correto | Lock por loja obrigatorio; lock por turno opcional em cenarios especificos |
| UPSERT/INSERT em lote | Correto | Usar Query Builder (`upsert`, `insertOrIgnore`) com batches |
| Idempotencia em itens/pagamentos | Correto e critico | Preferir `line_no`; fallback `row_hash` unico |
| Evitar tempestade de observers | Correto | Importacao `pdv_*` sem events Eloquent; recalc coalescido |
| Timezone explicito no payload | Correto | Exigir offset ou UTC no contrato |
| Retencao do RAW + limpeza | Correto | Ex.: 30 dias com scheduler de purge |

## 10.3 Ajustes importantes para este projeto

1. **Fila em producao nao pode ser `sync`**.  
No projeto, `config/queue.php` usa default `env('QUEUE_CONNECTION', 'database')`, mas o `.env.example` ainda traz `QUEUE_CONNECTION=sync`. Para este webhook, a recomendacao e **`redis` em producao** (queue + lock/cache).

2. **Sem scheduler hoje**.  
Atualmente `routes/console.php` nao define schedule operacional. Para limpeza de RAW, retry interno e monitoramento, sera necessario adicionar tarefas agendadas.

3. **`insertOrIgnore` com cuidado em MySQL/MariaDB**.  
Ele e excelente para dedupe, mas pode mascarar erros como warning. Para entidades criticas, combinar com validacoes e metricas de linhas afetadas.

4. **Assinatura com replay protection (idempotencia primeiro)**.  
Usar `X-PDV-Timestamp` + HMAC no formato `timestamp.rawBody`, mas com ordem:
   - se `sync_id` ja existe: retornar `200` idempotente sem bloquear por janela;
   - se `sync_id` e novo: aplicar janela de 10 min em modo configuravel:
     - `strict`: rejeita fora da janela;
     - `tolerant` (recomendado se offline for comum): aceita, marca risco e alerta.

5. **Controle de status de sync no banco**.  
Adicionar em `pdv_syncs`: `status`, `processed_at`, `attempts`, `last_error`, `payload_sha256`, `payload_bytes`.

6. **Politica de retencao separada (RAW vs metadados)**.  
`pdv_sync_payloads` (RAW): 30 dias.  
`pdv_syncs` (metadados): 12+ meses para auditoria/BI.

7. **Coalescer recalculo financeiro**.  
Ao final do processamento do sync, disparar jobs unicos por `store+date` (ou `store+turno`) para evitar metralhadora de recalculos via observers.

## 10.4 Arquitetura operacional recomendada (definitiva)

1. `POST /api/v1/pdv/sync` valida assinatura + payload minimo.
2. Verifica duplicidade por `sync_id` antes de bloquear por timestamp.
3. Se novo sync, aplica politica de timestamp (`strict` ou `tolerant`).
4. Transacao curta:
   - `insertOrIgnore` em `pdv_syncs` (se duplicado, responder 200 imediatamente).
   - inserir RAW em `pdv_sync_payloads`.
   - marcar status `queued`.
5. Dispatch de `ProcessPdvSyncJob(sync_id)` (job unico).
6. No job:
   - lock por loja (`pdv:store:{id}`) para preservar ordem.
   - UPSERT de turnos e pagamentos.
   - INSERT/UPSERT de vendas.
   - INSERT/UPSERT idempotente de itens/pagamentos (line_no ou row_hash).
   - atualizar status `processed` ou `failed`.
7. Dispatch de recalc coalescido por loja/data.
8. Scheduler para limpeza/retencao de RAW e monitoramento de backlog.

## 10.5 Contrato HTTP recomendado para o Agent

- `200`: recebido, processado, ou duplicado (idempotente, inclusive fora da janela quando duplicado)
- `422`: payload invalido (nao insistir)
- `401/403`: assinatura invalida (nao insistir)
- `500/503`: erro transitorio (pode retry)

## 10.6 Idempotencia em filhos (itens/pagamentos) - decisao

Preferencia de implementacao:

1. **Opcao A (melhor)**: incluir `line_no` no payload e usar unique:
   - itens: `(store_id, id_operacao, line_no)`
   - pagamentos: `(store_id, id_operacao, line_no)`
2. **Opcao B (fallback)**: gerar `row_hash` e usar unique `(row_hash)`.

Sem isso, reprocessamento parcial pode deixar venda sem itens/pagamentos completos.

## 11) Checklist complementar de performance e escala

- [ ] Definir e documentar HMAC global com timestamp e politica `strict/tolerant`.
- [ ] Garantir `QUEUE_CONNECTION=redis` e `CACHE_STORE=redis` em producao.
- [ ] Garantir que `sync_id` duplicado retorna `200` sem bloqueio de janela.
- [ ] Criar job de processamento unico por `sync_id`.
- [ ] Implementar lock por loja no processamento.
- [ ] Implementar batch upsert/insert em todas as tabelas `pdv_*`.
- [x] Implementar idempotencia em itens/pagamentos (`line_no` ou `row_hash`).
- [ ] Implementar recalc coalescido por loja/data (evitar tempestade de jobs).
- [ ] Adicionar scheduler para purge de RAW (30 dias), retries internos e health checks.
- [ ] Preservar metadados (`pdv_syncs`) por 12+ meses.
- [ ] Monitorar backlog da fila, taxa de erro por loja e latencia de processamento.

## 12) Decisoes operacionais fechadas

- HMAC global.
- Timestamp de referencia: 10 minutos.
- Ordem de validacao: duplicado por `sync_id` primeiro; janela depois.
- Modo recomendado para offline comum: `PDV_TIMESTAMP_MODE=tolerant`.
- Retencao: RAW 30 dias; metadados 12+ meses.
- Fila/caches de producao: Redis.

## 13) Status de implementacao no codigo (atualizado em 2026-02-11)

### 13.1 Ja implementado

- Webhook publico `POST /api/v1/pdv/sync`.
- Middleware de assinatura `pdv.signature`:
  - HMAC global (`X-PDV-Signature`) no formato `hash_hmac('sha256', timestamp.rawBody, secret)`;
  - validacao de `X-PDV-Timestamp`;
  - respostas `401/403` para headers/assinatura invalidos.
- Ingestao rapida e idempotente no controller:
  - checa duplicado por `sync_id` primeiro;
  - aplica politica de timestamp (`strict`/`tolerant`) apenas para sync novo;
  - persiste `pdv_syncs` + `pdv_sync_payloads` em transacao curta;
  - enfileira `ProcessPdvSyncJob`.
- Estruturas de banco para recebimento:
  - `pdv_syncs`, `pdv_sync_payloads`, `pdv_store_mappings`;
  - `pdv_turnos`, `pdv_turno_pagamentos`, `pdv_vendas`, `pdv_venda_itens`, `pdv_venda_pagamentos`.
- Processamento assinc no job:
  - job unico por sync (`ShouldBeUniqueUntilProcessing`);
  - lock por loja (`Cache::lock`);
  - `upsert` em lote de turnos/pagamentos de turno;
  - `upsert` em lote de vendas/itens/pagamentos com idempotencia por constraint;
  - fallback de idempotencia de filhos via `row_hash` quando `line_no` nao vier no payload;
  - atualizacao de status (`processing` -> `processed` / `failed`) e `attempts`.
- Observabilidade operacional inicial:
  - endpoint admin `GET /api/v1/admin/pdv/syncs` (filtros por status/loja/janela);
  - endpoint admin `GET /api/v1/admin/pdv/syncs/metrics` (backlog, falhas 24h, latencia, lojas sem sync no limiar);
  - logs estruturados de ingestao/processamento com `sync_id`, `store_pdv_id`, `status`, `duration_ms`.
- Housekeeping:
  - comando `pdv:purge-raw-payloads` (retencao RAW);
  - comando `pdv:retry-failed` (retry controlado de `failed`);
  - scheduler configurado para purge diario e retry periodico (com flag de enable por env).
- Testes de webhook para contrato basico:
  - assinatura invalida;
  - duplicado por `sync_id`;
  - `strict` rejeitando novo fora da janela;
  - `tolerant` aceitando novo fora da janela com flag de risco.

### 13.2 Pendente para fechar o ciclo operacional

- Configurar e validar Redis em producao (`QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`).
- Rodar migrations em ambiente alvo.
- Validar em producao os comandos de housekeeping e retry (`pdv:purge-raw-payloads`, `pdv:retry-failed`).
- Integrar alerta externo (Slack/WhatsApp/email) para:
  - loja sem sync acima do limiar;
  - aumento de `failed`.
- Evolucao contratual recomendada no agente:
  - enviar `line_no` em `itens[]` e `pagamentos[]` para chave natural explicita.

## 14) Retorno do time Hiper (2026-02-11)

Foi confirmado pelo time do agente PDV:

- `sync_id` deterministico esta implementado.
- outbox/retry com replay esta implementado.
- `id_operacao` e `id_turno` sao estaveis para idempotencia por loja.

Pontos pendentes do lado agente (bloqueios de integracao final):

- HMAC ainda nao implementado (agente ainda usa Bearer fixo).
- datetimes ainda sem timezone explicito.
- `line_no` ainda nao enviado em itens/pagamentos.
- ajuste de politica de retry por status HTTP ainda pendente.

Mitigacoes temporarias aplicadas no backend:

- fallback opcional de autenticacao via Bearer (`PDV_ALLOW_BEARER_FALLBACK`, `PDV_BEARER_TOKEN`);
- parser de datetime naive com timezone configuravel (`PDV_NAIVE_DATETIME_TIMEZONE`) normalizando para UTC.

Documento de consolidacao: `docs/ALINHAMENTO_HIPER_WEBHOOK_PDV_SYNC_V2.md`.

## 15) Validacao E2E real (2026-02-11)

Teste realizado com payload real do agente (formato equivalente ao capturado no n8n):

- autenticacao em modo transicao via Bearer fallback;
- primeiro POST: `200` com status `queued`;
- segundo POST com mesmo `sync_id`: `200` com status `duplicate`;
- processamento assincrono concluiu em `processed`;
- persistencia confirmada em `pdv_syncs`, `pdv_sync_payloads`, `pdv_turnos`, `pdv_vendas`, `pdv_venda_itens`, `pdv_venda_pagamentos`;
- sem duplicacao em filhos (`row_hash` com grupos duplicados = 0).

Script de probe utilizado: `scripts/pdv_ingest_probe.php`.

Resultados adicionais:

- com mapping de loja (`pdv_store_id=10 -> store_id=1`), o `risk_flag` `store_mapping_missing` foi eliminado;
- validacao em modo HMAC (`--auth=hmac`) retornou fluxo nominal sem riscos (`risk_flags=[]`).

## 16) Atualizacao de infraestrutura Redis/Fila (2026-02-11)

Com base na nota tecnica de servidor e no estado atual do codigo:

- Redis do host esta adequado para este workload (local-only, nao exposto publicamente).
- O codigo ja suporta queue/cache em Redis.
- O maior risco restante e operacional: ambiente subir com `QUEUE_CONNECTION=sync` ou sem worker/scheduler ativos.

Decisoes recomendadas para producao:

1. `QUEUE_CONNECTION=redis` e `CACHE_STORE=redis`.
2. Worker no Laravel Toolkit com:
   - timeout `120`/`180`,
   - `max-jobs=500`,
   - `max-time=3600`,
   - sem `stop-when-empty`.
3. Scheduler a cada minuto (`schedule:run`) no Toolkit.
4. Alinhar `REDIS_QUEUE_RETRY_AFTER` acima do timeout do worker (ex.: `300` > `180`).
5. Executar `php artisan pdv:infra-check` como gate de prontidao antes do go-live.
6. Validar worker ativo com `php artisan pdv:queue-smoke --wait=20`.

Documento operacional detalhado:
- `docs/INFRA_REDIS_FILAS_PLESK_PDV.md`.
