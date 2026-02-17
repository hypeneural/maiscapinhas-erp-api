# Análise do Fluxo de Sincronização PDV (`/api/v1/pdv/sync`)

Esta documentação detalha como a API processa os eventos enviados pelo Agente PDV, respondendo especificamente às dúvidas sobre `turno_closure`, `mixed`, e o processamento de dados de fechamento e snapshots.

## 1. Tratamento dos Tipos de Evento (`event_type`)

O tratamento inicial ocorre no **Controller** (`PdvSyncController::ingest`) e o processamento pesado no **Job** (`ProcessPdvSyncJob`).

### Recebimento (Controller)
O endpoint aceita explicitamente os três tipos de eventos abaixo. Se o tipo recebido for válido, ele é normalizado e salvo no banco de dados (`pdv_syncs`).

*   `sales`
*   `turno_closure`
*   `mixed`

> **Nota:** Se o `event_type` for diferente desses, o sistema gera um *warning* mas processa como `sales` (com uma flag de risco).

### Processamento (Job)
A classe `ProcessPdvSyncJob` é agnóstica ao `event_type` para a maioria das operações. Isso significa que **ela processa todas as listas de dados presentes no JSON (`vendas`, `turnos`, `snapshot_vendas`, `snapshot_turnos`)**, independentemente se o evento é `sales`, `turno_closure` ou `mixed`.

## 2. Respostas às Dúvidas Específicas

### "Você está recebendo os dados json dos tipos de eventos turno_closure e mixed e tratando eles de alguma forma também?"

**Sim.** Ambos são recebidos e persistidos.
*   **Controller:** Valida o schema JSON e armazena o payload bruto.
*   **Job:** Percorre os arrays de dados dentro do payload e atualiza as tabelas finais.

### "Exemplo o turno_closure você está pegando algum dado de fechamento de turno dele para armazenar e detalhar?"

**Sim.** Quando chega um evento (seja `turno_closure` ou outro) contendo objetos na lista `turnos`, o sistema extrai e salva dados detalhados do fechamento na tabela `pdv_turnos`.

Os seguintes campos de fechamento são mapeados e armazenados (se presentes no JSON):
*   `fechado`: Booleano indicando se o turno está fechado.
*   `data_hora_fechamento`: Data/hora do fechamento.
*   `total_declarado`: Valor informado pelo operador.
*   `total_falta`: Valor de diferença (falta).
*   `total_sobra`: Valor de diferença (sobra).
*   `closure_uuid`: ID único do fechamento (V5/V4).
*   `falta_uuid` / `sobra_uuid`: IDs das operações financeiras de quebra de caixa.
*   **Pagamentos de Fechamento:** Os detalhamentos de pagamentos do fechamento (declarado, falta, sobra) são salvos na tabela `pdv_turno_pagamentos`.

### "E está usando ele também na parte do snap (snapshot)?"

**Sim.** A lógica para `snapshot_turnos` (o "snap" de turnos) reutiliza a mesma tabela de destino dos turnos normais.
*   Dados vindos de `turnos` (lista principal) -> Atualizam `pdv_turnos`.
*   Dados vindos de `snapshot_turnos` -> **Atualizam a mesma tabela `pdv_turnos`.**

O sistema faz um *upsert* (inserir ou atualizar) baseado na chave única (`store_pdv_id`, `canal`, `id_turno`). Ou seja, o snapshot garante que o estado do turno no banco de dados reflita o estado mais recente conhecido pelo PDV, incluindo seus dados de fechamento.

> **Importante:** Para Vendas, é diferente.
> *   `vendas` (lista principal) -> Atualiza `pdv_vendas` (detalhado).
> *   `snapshot_vendas` -> Atualiza `pdv_vendas_resumo` (tabela separada, apenas cabeçalho/resumo).

### "Se chega type mixed, você está processando a venda que está junto no json também?"

**Sim.** O processador varre a lista de `vendas` independentemente do tipo do evento.
Se o evento for `mixed` e contiver vendas no array `vendas`, elas serão processadas detalhadamente (itens, pagamentos, impostos) e salvas em `pdv_vendas`, `pdv_venda_itens` e `pdv_venda_pagamentos`.

## 3. Resumo Técnico do Mapeamento

| Seção do JSON | Tabela no Banco de Dados | Descrição |
| :--- | :--- | :--- |
| `turnos` | `pdv_turnos` | Turnos ativos ou recém-fechados da janela atual. Inclui dados de fechamento (sobra/falta). |
| `snapshot_turnos` | `pdv_turnos` | Histórico recente de turnos para garantir consistência. Atualiza a **mesma tabela** que os turnos normais. |
| `vendas` | `pdv_vendas` | Vendas detalhadas (itens, pagamentos) da janela atual. |
| `snapshot_vendas` | `pdv_vendas_resumo` | Resumo das últimas vendas (apenas totais, sem itens detalhados) para validação rápida. |

## 4. Validações de Consistência (Logs de Alerta)

O sistema verifica a coerência do evento `mixed` e `turno_closure` e gera alertas (logs) se houver algo estranho, mas **não bloqueia** o processamento:
1.  **Aviso:** Se `event_type` for `turno_closure` mas vierem `vendas` junto.
2.  **Aviso:** Se `event_type` for `mixed` mas *não* houver `vendas`.
3.  **Aviso:** Se `event_type` for `mixed` mas *não* houver nenhum turno fechado na lista.
