# PDV v3 Go-Live: Rollout, Carga e Rollback

Data: 2026-02-12  
Escopo: PR-40 (hardening e rollout controlado)

## 1) Pre-flight checklist

- Migracoes aplicadas: PR-31 ate PR-39.
- Worker de fila ativo (`queue:work`) para fila `pdv`.
- Scheduler ativo para:
  - `pdv:ops-monitor --json`
  - `pdv:retry-failed` (se habilitado)
- `PDV_SUPPORTED_SCHEMA_VERSIONS=2.0,3.0`
- `PDV_MONITOR_SILENT_STORE_THRESHOLD_MINUTES` e `PDV_MONITOR_MAX_STALE_STORES` configurados.
- Endpoints admin de metrics respondendo:
  - `/api/v1/admin/pdv/syncs/metrics`

## 2) Carga controlada

Script:

```bash
php scripts/pdv_v3_load_test.php \
  --url=https://api.seudominio.com/api/v1/pdv/sync \
  --secret=SEU_SEGREDO_HMAC \
  --fixture=tests/Fixtures/pdv/v3/mixed_caixa_loja_collision.json \
  --stores=15 \
  --iterations=20
```

Metrica minima a registrar:
- `latency_avg_ms`
- `latency_p95_ms`
- `throughput_rps`
- distribuicao de HTTP status

Validacao complementar no banco:

```sql
-- ultimos syncs por schema
SELECT schema_version, COUNT(*) AS total
FROM pdv_syncs
WHERE received_at >= NOW() - INTERVAL '1 hour'
GROUP BY schema_version;

-- saude por loja (stale > 2h)
SELECT store_pdv_id, MAX(received_at) AS last_sync
FROM pdv_syncs
GROUP BY store_pdv_id
HAVING MAX(received_at) < NOW() - INTERVAL '2 hour';
```

## 3) Rollout por ondas

### Onda 1 (piloto: 1-2 lojas)
- Duracao recomendada: 1 dia util.
- Gate para avancar:
  - sem aumento relevante de `failed` em `pdv_syncs`;
  - sem `stale_stores_high` recorrente;
  - consultas PDV v3 atendendo frontend piloto.

### Onda 2 (grupo intermediario)
- Duracao recomendada: 2-3 dias.
- Gate para avancar:
  - latencia de processamento dentro do baseline;
  - monitor estavel;
  - sem regressao visivel em payload v2.

### Onda 3 (full)
- Habilitar para todas as lojas.
- Manter monitoramento reforcado por 72h.

## 4) Gatilhos de rollback

Rollback imediato se ocorrer:
- erro sistemico de ingestao (taxa de falha acima do limite acordado);
- acumulacao de fila sem consumo;
- divergencia de dados de negocio sem correcao por snapshot;
- degradacao severa de performance com impacto operacional.

## 5) Plano de rollback

### 5.1 Rollback de schema version (sem migracao)
- Reduzir aceitação temporaria para v2:
  - `PDV_SUPPORTED_SCHEMA_VERSIONS=2.0`
- Limpar config cache e recarregar workers.

### 5.2 Rollback de deploy de aplicacao
- Reverter para release anterior estavel.
- Executar smoke:
  - ingestao v2
  - processamento fila
  - metrics admin

### 5.3 Rollback de migracoes sensiveis
- Evitar rollback destrutivo durante incidente.
- Se necessario, aplicar rollback apenas em janela de manutencao aprovada.
- Priorizar rollback funcional (config/release) antes de rollback fisico de tabela.

## 6) Comunicacao de incidente

- Abrir incidente com timestamp de inicio, lojas afetadas e sintomas.
- Notificar:
  - time backend
  - time integracao PDV
  - operacao/gestao
- Atualizar a cada 30 min ate estabilizacao.

## 7) Pos deploy validation command set

```bash
php artisan pdv:ops-monitor --json
php artisan pdv:infra-check --json
php artisan queue:failed
```

Conferencias API:
- `/api/v1/admin/pdv/syncs?per_page=20`
- `/api/v1/admin/pdv/syncs/metrics`
- `/api/v1/pdv/reports/vendas?store_pdv_id=<ID>&from=<YYYY-MM-DD>&to=<YYYY-MM-DD>`
