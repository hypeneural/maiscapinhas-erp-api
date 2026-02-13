# Validacao Pre-Deploy - JSON Webhook PDV (v3.0/v3.1)

Data: 2026-02-13  
Projeto: `maiscapinhas-erp-api`  
Escopo: validar payloads recebidos no webhook PDV antes do deploy em producao

## 1) Veredito executivo

Status geral: **NAO esta 100% conforme ainda**.

Bloqueio atual confirmado:
- o backend rejeita payloads validos de turno aberto quando `duracao_minutos = null`.
- isso gera `422 validation` antes de enfileirar.

Impacto:
- eventos `sales` e `turno_closure` com turnos abertos podem falhar na borda de ingestao.
- no n8n, o node HTTP vai continuar retornando erro 422 nesses casos.

### Atualizacao apos resposta oficial do time Python (2026-02-13)

Com base nas respostas oficiais, o backend foi ajustado localmente para refletir o contrato real atual:

- `schema_version=3.0` continua oficial no agente, mesmo com `cnpj/login`.
- `duracao_minutos` pode vir `null` em turnos abertos.

Ajustes aplicados no codigo:
- `app/Http/Requests/Pdv/PdvSyncIngestRequest.php`
  - `turnos.*.duracao_minutos` => nullable
  - `snapshot_turnos.*.duracao_minutos` => nullable
  - `store.cnpj` => nullable
- `docs/schema_v3.0.json`
  - aceita `store.cnpj` e campos `login` opcionais

Status apos patch:
- blocker de validacao foi corrigido em codigo local.
- falta somente validacao E2E no ambiente alvo (provisao/producao) para cravar 100%.

## 2) Evidencias objetivas (reproducao)

### 2.1 Erro real recebido

Resposta reportada:

```json
{
  "error": "validation",
  "message": "Validation failed.",
  "details": {
    "turnos.0.duracao_minutos": [
      "The turnos.0.duracao_minutos field must be an integer."
    ]
  }
}
```

### 2.2 Regra atual do backend (causa raiz)

Arquivo: `app/Http/Requests/Pdv/PdvSyncIngestRequest.php`

- `turnos.*.duracao_minutos` esta como `['sometimes', 'integer', 'min:0']`
- `snapshot_turnos.*.duracao_minutos` esta como `['sometimes', 'integer', 'min:0']`

Sem `nullable`, qualquer `null` vira erro.

### 2.3 Prova local com bootstrap Laravel

Teste executado localmente com payload minimo contendo `duracao_minutos: null`:

- resultado: `FAIL`
- erro: `The turnos.0.duracao_minutos field must be an integer.`

Quando a regra e trocada para `['sometimes','nullable','integer','min:0']`:

- resultado: `PASS`

## 3) Compatibilidade com schema JSON (v3.0/v3.1)

### 3.1 O schema oficial permite `duracao_minutos = null`

Arquivos:
- `docs/schema_v3.0.json`
- `docs/schema_v3.1.json`

Nos dois schemas, `duracao_minutos` aceita:
- `integer`
- `null`

Conclusao:
- hoje a validacao Laravel esta **mais restritiva** que o schema JSON oficial.

### 3.2 Campos novos de v3.1 enviados com `schema_version=3.0`

Se `PDV_JSON_SCHEMA_VALIDATION_ENABLED=true`, existe outro risco importante:

- payload com `schema_version=3.0` + campos `store.cnpj` e `*.login`
- `schema_v3.0.json` nao conhece esses campos
- resultado: invalido por `additionalProperties`

Prova local:
- validando schema 3.0 com `store.cnpj` => `status=invalid`
- erro: `Additional object properties are not allowed: cnpj`

## 4) Analise dos payloads enviados

Payloads analisados (amostras do n8n):
- `event_type=turno_closure`
- `event_type=sales`
- ambos com `schema_version=3.0`
- ambos com `store.cnpj` e `login` em blocos de usuario
- ambos com varios turnos abertos contendo `duracao_minutos=null`

Resultado por bloco:
- `store`, `window`, `ops`, `integrity`: estrutura ok.
- `turnos`: **falha de tipo** por `duracao_minutos=null` na regra atual.
- `snapshot_turnos`: hoje estava com inteiros, mas se vier `null` tambem falha pela mesma regra.
- `vendas/snapshot_vendas`: estrutura geral ok para o contrato atual.

## 5) Estado da logica de normalizacao (stores/users)

A parte de normalizacao esta implementada no backend:

- Loja:
  - `app/Support/Pdv/PdvStoreResolver.php`
  - resolve por CNPJ, alias/nome e fallback por id com flags de risco.
- Usuario:
  - `app/Support/Pdv/PdvUserResolver.php`
  - resolve por login first, fallback por `pdv_user_id`, trata operador generico.
- Ingestao/job usam resolver:
  - `app/Http/Controllers/Api/V1/PdvSyncController.php`
  - `app/Jobs/ProcessPdvSyncJob.php`

Conclusao:
- a logica de normalizacao existe e esta alinhada com v3.1.
- o bloqueio atual e de validacao de tipo no request ingress.

## 6) Ajustes obrigatorios para ficar 100%

### P0 (obrigatorio antes de subir)

1. Ajustar regras de validacao:
- `turnos.*.duracao_minutos` => `['sometimes','nullable','integer','min:0']`
- `snapshot_turnos.*.duracao_minutos` => `['sometimes','nullable','integer','min:0']`

2. Alinhar versionamento do payload:
- se enviar `cnpj/login`, mandar `schema_version=3.1` e header `X-PDV-Schema-Version: 3.1`.
- se continuar em `3.0`, nao enviar campos exclusivos de 3.1 quando schema validation estiver ativa.

3. Ajustar `store.cnpj` para aceitar `null` no request (alinhar com schema 3.1):
- hoje: `['sometimes','string','max:18']`
- recomendado: `['sometimes','nullable','string','max:18']`

### P1 (fortalecimento)

1. Adicionar testes de regressao em `tests/Feature/Api/V1/PdvSyncWebhookTest.php`:
- aceitar turno aberto com `duracao_minutos=null` em `turnos`.
- aceitar `snapshot_turnos[].duracao_minutos=null`.
- validar comportamento de `schema_version=3.0` vs `3.1` com schema validation ligada.

2. Validar no pipeline:
- `php artisan test --filter=PdvSyncWebhookTest`

## 7) Checklist rapido de aceite pre-producao

1. Enviar payload `sales` com turno aberto (`duracao_minutos=null`) e confirmar `201`.
2. Enviar payload `turno_closure` igual ao da amostra e confirmar `201`.
3. Confirmar criacao em `pdv_syncs` com `status=queued`.
4. Rodar consumidor e validar transicao para `processed`.
5. Confirmar ausencia de 422 por `duracao_minutos` nos logs PDV.

## 8) Conclusao final

Nao esta 100% ainda por um blocker de validacao no ingress (`duracao_minutos` nullable).

Depois de aplicar os 3 ajustes P0 acima, o contrato fica coerente com o schema e com os payloads reais do agente, e ai sim o fluxo pode ser considerado pronto para subir sem esse erro recorrente de 422.
