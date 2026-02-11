# Analise de Infra Redis e Filas (Plesk) - Webhook PDV

Data: 2026-02-11  
Projeto: `maiscapinhas-erp-api`  
Escopo: estabilidade de ingestao e processamento do webhook `POST /api/v1/pdv/sync`

## 1) Diagnostico consolidado

## 1.1 Infra do servidor (recebido do time)

- Redis instalado e ativo (`5.0.3`), modo standalone.
- Redis acessivel apenas localmente (`127.0.0.1:6379`), sem exposicao publica.
- `requirepass` vazio (aceitavel neste cenario local-only).
- Politica de memoria: `noeviction`.
- PHP correto para Artisan no host: `/opt/plesk/php/8.2/bin/php`.
- Laravel Toolkit disponivel para gerenciar worker e scheduler.

## 1.2 Estado atual no codigo

- Fila Redis ja suportada em `config/queue.php`.
- Cache Redis ja suportado em `config/cache.php` + `config/database.php`.
- Job PDV ja desacoplado com `dispatch()->onQueue(config('pdv.queue_name'))`.
- Scheduler ja possui:
  - `pdv:purge-raw-payloads` (diario);
  - `pdv:retry-failed` (10 em 10 min, por flag).
- Scheduler heartbeat adicionado:
  - chave `pdv:scheduler:heartbeat` atualizada a cada minuto quando `schedule:run` esta ativo.
- Ajuste aplicado: `REDIS_QUEUE_BLOCK_FOR` agora parametriza `block_for` no `config/queue.php`.

## 1.3 Gap operacional real

1. Producao pode ficar em `QUEUE_CONNECTION=sync` se env nao for aplicado corretamente.
2. Sem worker ativo no Toolkit, jobs ficam acumulados sem consumo.
3. Sem `schedule:run` a cada minuto, automacoes de purge/retry nao executam.
4. Sem alinhamento `timeout` x `retry_after`, pode haver redelivery indevido.
5. Sem monitoramento de backlog/falhas, incidentes demoram para aparecer.

## 2) Estrutura Redis recomendada (producao)

Base recomendada para `.env`:

```dotenv
QUEUE_CONNECTION=redis
CACHE_STORE=redis
# SESSION_DRIVER=file   # API stateless; usar redis so se realmente precisar sessao

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_PREFIX=mc:api:

REDIS_QUEUE_CONNECTION=default
REDIS_QUEUE=default
REDIS_QUEUE_RETRY_AFTER=300
REDIS_QUEUE_BLOCK_FOR=5
REDIS_CACHE_CONNECTION=cache
REDIS_CACHE_LOCK_CONNECTION=default
```

Notas:
- `REDIS_PREFIX` evita colisao com outros apps no mesmo Redis.
- `REDIS_QUEUE_RETRY_AFTER` deve ser maior que timeout do worker.
- `noeviction` exige disciplina de TTL para cache e controle de payloads grandes (nao usar Redis para RAW payload).

## 3) Fila no Laravel Toolkit (Plesk)

Configuracao inicial recomendada para worker:

- `stop-when-empty`: desabilitado
- `timeout`: `120` ou `180`
- `max-jobs`: `500`
- `max-time`: `3600`

Racional:
- worker continuo evita latencia entre syncs de 10 min;
- reciclagem periodica evita degradacao por memoria;
- timeout + retry_after alinhados reduz risco de job duplicado.

Pos deploy:

```bash
/opt/plesk/php/8.2/bin/php artisan queue:restart
```

## 4) Scheduler (obrigatorio)

Criar no Toolkit tarefa a cada 1 minuto:

```bash
/opt/plesk/php/8.2/bin/php /var/www/vhosts/maiscapinhas.com.br/api.maiscapinhas.com.br/artisan schedule:run
```

Sem isso:
- `pdv:purge-raw-payloads` nao roda;
- `pdv:retry-failed` nao roda;
- backlog de falhas tende a crescer sem controle.

## 5) Checklist de ativacao (ordem pratica)

1. Ajustar `.env` de producao para Redis (fila + cache).
2. Limpar cache de config:
   - `/opt/plesk/php/8.2/bin/php artisan config:clear`
   - `/opt/plesk/php/8.2/bin/php artisan cache:clear`
3. Ativar worker no Toolkit com parametros recomendados.
4. Criar `schedule:run` por minuto no Toolkit.
5. Testar ingestao real:
   - primeiro POST: `201`;
   - duplicado: `200`;
   - confirmar consumo de job e status `processed`.
6. Validar operacao:
   - fila sem backlog crescente;
   - sem explosao de `failed_jobs`;
   - comandos agendados executando no horario esperado.

## 6) Operacao e incidentes

Comandos essenciais:

```bash
/opt/plesk/php/8.2/bin/php artisan pdv:infra-check
/opt/plesk/php/8.2/bin/php artisan pdv:infra-check --json
/opt/plesk/php/8.2/bin/php artisan pdv:queue-smoke --wait=20
/opt/plesk/php/8.2/bin/php artisan queue:failed
/opt/plesk/php/8.2/bin/php artisan queue:retry all
/opt/plesk/php/8.2/bin/php artisan queue:flush
```

Interpretacao rapida do `pdv:infra-check`:
- `errors > 0`: nao pronto para go-live (corrigir antes de abrir trafego).
- `warnings > 0`: operacao possivel, mas com risco/ajustes pendentes.

Interpretacao rapida do `pdv:queue-smoke`:
- sucesso: worker ativo e consumindo a fila `pdv`;
- timeout/falha: worker parado, fila errada ou problema de conexao do worker.

Sinais de alerta para acionar time:
- loja sem sync > 20 min;
- aumento brusco de `failed`;
- backlog de `pdv` crescendo por mais de 2 ciclos.

## 7) Decisao tecnica final

Para este cenario (15 lojas, webhook a cada 10 min, retry/outbox), o desenho mais seguro e escalavel permanece:

1. ingestao rapida e idempotente;
2. persistencia RAW + metadados;
3. processamento assincrono em Redis queue;
4. lock por loja;
5. operacao via Toolkit (worker + scheduler);
6. observabilidade e rotina de resposta a incidentes.
