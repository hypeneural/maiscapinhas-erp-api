# Plano de Normalizacao Store/User Mappings PDV (2026-02-13)

## 1) Diagnostico real
- As tabelas `pdv_store_mappings` e `pdv_user_mappings` ja existiam.
- O problema principal era de modelagem:
  - `pdv_store_mappings` assumia `pdv_store_id` unico (incompativel com colisao entre lojas).
  - `pdv_user_mappings` era por loja (`store_pdv_id + pdv_user_id`), mas o cenario atual exige mapeamento global por `pdv_user_id`.
- Resultado operacional observado: `store_mapping_missing`/`user_mapping_missing` recorrentes e risco de ambiguidade.

## 2) Decisoes aprovadas
- `pdv_user_id=66` mapeia para `users.id=24` (Larissa Gomes).
- `users.id=15` (Larissa Rodrigues) nao entra como vendedora.
- Cadastrar usuarios faltantes: `Xochil`, `Kelli`, `Rafaeli`, `Iagoh`.
- Atualizar CNPJ das 12 lojas em `stores.cnpj`.
- Resolucao de loja por `id + alias` com fallback seguro e sinalizacao de risco.
- Backend permanece `schema_version=3.0` only.

## 3) DDL alvo (estado final)

### 3.1 `pdv_store_mappings`
- Colunas relevantes:
  - `pdv_store_id` (int)
  - `alias` (varchar 100)
  - `cnpj` (varchar 18, nullable)
  - `store_id` (fk stores.id)
  - `active` (bool)
- Chave principal funcional:
  - `UNIQUE (pdv_store_id, alias)` (`pdv_store_mappings_unique_pdv_alias`)
- Indices operacionais:
  - `(pdv_store_id, active)`
  - `(store_id, active)`
  - `(cnpj, active)`

### 3.2 `pdv_user_mappings`
- Modelo global por usuario PDV:
  - `pdv_user_id` (unico)
  - `user_id` (nullable)
  - `is_store_operator` (bool)
  - `pdv_user_name` (nullable)
  - `pdv_user_login` (nullable)
  - `store_pdv_id` legado (nullable, apenas historico)
  - `source`, `confidence`, `active`
- Chave principal funcional:
  - `UNIQUE (pdv_user_id)` (`pdv_user_mappings_unique_pdv_user_id`)
- Indices operacionais:
  - `(active)`
  - `(user_id, active)`
  - `(is_store_operator, active)`

## 4) Seed/bootstrap e ordem de execucao

### 4.1 Ordem recomendada
1. Rodar migration de normalizacao:
   - `2026_02_13_000350_normalize_pdv_mapping_tables.php`
2. Rodar bootstrap idempotente:
   - `php artisan pdv:bootstrap-mappings`
3. Validar risco e processamento:
   - `php artisan pdv:ops-monitor --json`

### 4.2 Comandos uteis
- Simulacao sem gravar:
  - `php artisan pdv:bootstrap-mappings --dry-run`
- Bloco isolado:
  - `php artisan pdv:bootstrap-mappings --only=cnpjs`
  - `php artisan pdv:bootstrap-mappings --only=users`
  - `php artisan pdv:bootstrap-mappings --only=store-mappings`
  - `php artisan pdv:bootstrap-mappings --only=user-mappings`

### 4.3 Conteudo aplicado pelo bootstrap
- Atualiza CNPJ das 12 lojas operacionais.
- Garante 4 usuarios faltantes (cadastro minimo tecnico).
- Popula/atualiza `pdv_store_mappings` por `(pdv_store_id, alias)`.
- Popula/atualiza `pdv_user_mappings` globais:
  - vendedores reais com `user_id`
  - operadores genericos com `is_store_operator=1` e `user_id=NULL`

## 5) Mudancas em runtime (API/Job/Relatorios/Commands)

### 5.1 Ingestao webhook (`PdvSyncController`)
- Resolve loja via `PdvStoreResolver`:
  - `id+alias` -> `id+nome` -> `cnpj` -> fallback por `id` (unico).
- Novos risk flags suportados:
  - `store_mapping_ambiguous`
  - `store_mapping_by_id_fallback`
- Mantem flags anteriores (`store_mapping_missing`, `store_alias_mismatch`, etc).

### 5.2 Processamento (`ProcessPdvSyncJob`)
- Resolve loja com mesmo resolver da ingestao.
- Resolve usuario com `PdvUserResolver` global por `pdv_user_id`.
- `is_store_operator=1` retorna `null` sem `user_mapping_missing`.
- Lock key de loja sem `store_id` agora considera alias para evitar colisao entre lojas com mesmo `store_pdv_id`.

### 5.3 Relatorios (`PdvReportsController`)
- Novo parametro opcional: `store_alias`.
- Se vier apenas `store_pdv_id` e houver mais de um mapping ativo:
  - retorna `422` pedindo `store_id` ou `store_alias`.
- `ranking-vendedor-loja` deixa de depender de join em `pdv_store_mappings` por `pdv_store_id` (usa `v.store_id`).

### 5.4 Comandos operacionais
- `pdv:map-store`:
  - upsert por `(pdv_store_id, alias)`
  - suporte a `--cnpj`
- `pdv:map-user`:
  - global por `pdv_user_id`
  - suporte a `--operator`
  - `user_id` opcional (obrigatorio apenas para nao-operador)
- Novo comando:
  - `pdv:bootstrap-mappings` (idempotente com contadores `inserted/updated/skipped`)

### 5.5 Monitoramento/Admin
- `PdvOpsMonitorCommand` e `PdvSyncAdminController` passam a considerar `store_pdv_id + alias` para saude de loja silenciosa.
- Reduz falso positivo quando IDs locais colidem.

## 6) Checklist pos-deploy
- [ ] Migration executada sem erro.
- [ ] `pdv:bootstrap-mappings --dry-run` revisado.
- [ ] `pdv:bootstrap-mappings` executado com sucesso.
- [ ] Ultimos syncs sem `store_mapping_missing` para lojas mapeadas.
- [ ] Ultimos syncs sem `user_mapping_missing` para vendedores mapeados.
- [ ] Endpoints de relatorio com `store_pdv_id` ambiguo retornam `422` orientativo.
- [ ] Endpoint com `store_alias` desambigua corretamente.
- [ ] `pdv:ops-monitor --json` sem falso positivo por colisao de `store_pdv_id`.

## 7) Queries operacionais de suporte

### 7.1 Auditoria de mappings de loja
```sql
SELECT pdv_store_id, alias, cnpj, store_id, active
FROM pdv_store_mappings
ORDER BY pdv_store_id, alias;
```

### 7.2 Duplicidades residuais por chave composta
```sql
SELECT pdv_store_id, COALESCE(alias, '<<NULL>>') alias_key, COUNT(*) rows_count
FROM pdv_store_mappings
GROUP BY pdv_store_id, COALESCE(alias, '<<NULL>>')
HAVING COUNT(*) > 1;
```

### 7.3 Auditoria de mappings de usuario
```sql
SELECT pdv_user_id, pdv_user_name, pdv_user_login, user_id, is_store_operator, active
FROM pdv_user_mappings
ORDER BY pdv_user_id;
```

### 7.4 Syncs recentes com novos flags
```sql
SELECT id, sync_id, store_pdv_id, store_alias, store_id, risk_flags, received_at
FROM pdv_syncs
WHERE received_at >= NOW() - INTERVAL 2 HOUR
ORDER BY id DESC;
```

### 7.5 Pre-check de conflitos (antes de migrar)
- Arquivo: `docs/sql/pdv_mappings_precheck_conflicts.sql`
