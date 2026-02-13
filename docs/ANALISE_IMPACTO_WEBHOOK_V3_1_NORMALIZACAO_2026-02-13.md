# Analise de Impacto - Webhook PDV v3.1 (CNPJ + Login)

Data: 2026-02-13  
Projeto: `maiscapinhas-erp-api`

## 1) Resumo executivo
- O time do agente evoluiu corretamente o contrato com identificadores mais fortes:
  - `store.cnpj` para identidade universal de loja.
  - `*.login` em operador/responsavel/vendedor para identidade funcional de usuario no ERP.
- Nosso backend ja avancou bastante na normalizacao (resolvers, mappings compostos, bootstrap, risco de ambiguidade).
- Ainda existem gaps importantes para aproveitar 100% do ganho do v3.1:
  - prioridade de resolucao de loja ainda nao comeca por CNPJ.
  - resolucao de usuario ainda e centrada em `pdv_user_id` (nao em `login`).
  - campos de `login` ainda nao sao persistidos nas tabelas operacionais.
  - contrato de versao para `3.1` precisa ser fechado (ingress/schema/docs).

## 2) O que ja esta pronto (base atual)
- Resolver de loja com suporte a CNPJ:
  - `app/Support/Pdv/PdvStoreResolver.php`
- Resolver global de usuario PDV:
  - `app/Support/Pdv/PdvUserResolver.php`
- Ingestao e job usam resolver unico de loja e flags de ambiguidade:
  - `app/Http/Controllers/Api/V1/PdvSyncController.php`
  - `app/Jobs/ProcessPdvSyncJob.php`
- Normalizacao de mappings aplicada no codigo:
  - migration `database/migrations/2026_02_13_000350_normalize_pdv_mapping_tables.php`
  - comando `app/Console/Commands/PdvBootstrapMappingsCommand.php`
- Relatorios ja tratam `store_pdv_id` ambiguo e aceitam `store_alias`:
  - `app/Http/Controllers/Api/V1/PdvReportsController.php`

## 3) Gaps para v3.1 (prioridade real)

### G0 - Contrato de versao v3.1
- Hoje o backend esta congelado em `3.0`.
- Se o agente subir `schema_version=3.1`, teremos rejeicao por versao.
- Necessario definir janela de compatibilidade:
  - transicao: aceitar `3.0` e `3.1`
  - corte: `3.1` only

### G1 - Loja: ordem de binding
- Regra recomendada pelo time do agente:
  1. `store.cnpj`
  2. `store.alias`
  3. `id_ponto_venda` (apenas fallback seguro)
- Hoje no resolver a ordem pratica esta:
  1. alias
  2. nome
  3. CNPJ
  4. id fallback
- Melhoria: inverter para CNPJ primeiro para eliminar erro humano de alias.

### G2 - Usuario: binding por login
- v3.1 traz `login` em:
  - `turnos[].operador.login`
  - `turnos[].responsavel.login`
  - `vendas[].itens[].vendedor.login`
  - `resumo.by_vendor[].login`
- Hoje resolvemos usuario por `pdv_user_id` global.
- Melhoria: resolver por `login` primeiro, com fallback em `pdv_user_id`.

### G3 - Persistencia de login
- Campos `login` ainda nao entram nas tabelas operacionais do PDV.
- Isso reduz rastreabilidade e troubleshooting quando `id_usuario` muda por restore/reimplantacao.
- Melhoria: persistir login em turnos/itens/snapshot e atualizar `pdv_usuarios.login_hiper`.

### G4 - Observabilidade orientada ao novo contrato
- Precisamos KPI explicitos de qualidade de binding:
  - `% resolucao loja por cnpj`
  - `% resolucao usuario por login`
  - `% fallback por id`
  - `store_mapping_ambiguous` e `user_mapping_missing` por loja

## 4) Riscos se nao ajustar agora
- Continuar com erro por alias errado mesmo tendo CNPJ disponivel.
- Persistir dependencia em `id_usuario` quando o v3.1 trouxe identificador mais estavel (`login`).
- Perder ganho real da evolucao de contrato e manter ruido operacional.

## 5) Plano de execucao recomendado
- PR-49 (P0): Contrato/ingress v3.1 + schema/versionamento.
- PR-50 (P0): Binding de loja por CNPJ first + usuario por login first.
- PR-51 (P1): Persistencia de login em tabelas operacionais + backfill.
- PR-52 (P1): Monitoramento/KPIs e checklist E2E de identidade.

## 6) Criterio de aceite v3.1
- Webhook v3.1 aceito sem fallback manual.
- `store_mapping_missing` tende a zero para lojas com CNPJ valido.
- `user_mapping_missing` reduzido por binding de login.
- Casos ambiguos viram excecao rastreavel, nao comportamento silencioso.
- Relatorios de vendedor sem distorcao por operador generico.
