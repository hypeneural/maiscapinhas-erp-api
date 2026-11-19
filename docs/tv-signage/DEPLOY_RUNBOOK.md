# Runbook de Implantação — TV Digital Signage (Plesk / Produção)

> **Pré-requisito obrigatório:** aprovar formalmente as PRs de Backend (`recovery/production-tv-signage-backend`) e Frontend (`recovery/production-tv-signage-frontend`) após revisão dos relatórios de auditoria e testes.

## 1. Verificações Pré-Deploy

1. Confirmar que **não existem** webhooks ou GitHub Actions disparando deploy não supervisionado nos repositórios `maiscapinhas-erp-api` e `maiscapinhas-7d425b09`.
2. Realizar **backup prévio** do diretório `api.maiscapinhas.com.br/` (excluindo `storage/app/public/` se muito grande, preservando `.env`, `.user.ini` e `public/.user.ini`), de `erp.maiscapinhas.com.br/` e um dump lógico das tabelas `tv_*` e `migrations`.
3. Confirmar no banco de produção que as 5 migrations `2026_03_03_100000..140000` **já constam registradas** na tabela `migrations` (batches 43–47). Assim, `php artisan migrate --force` será um no-op seguro para TV (nunca executar `migrate:fresh` ou `migrate:refresh`).

## 2. Sequência de Implantação — Backend (`api.maiscapinhas.com.br`)

1. Fazer merge da branch `recovery/production-tv-signage-backend` na `main` do GitHub via PR revisada.
2. No Plesk (ou via procedimento oficial de deploy da hospedagem), sincronizar o repositório `git/maiscapinhas-erp-api` com `origin/main` e implantar o código em `api.maiscapinhas.com.br/`.
3. **Preservar configurações operacionais fora do Git:**
   - `.env` (não sobrescrever!)
   - `.user.ini` e `public/.user.ini` contendo limites para upload de vídeos:
     ```ini
     upload_max_filesize = 500M
     post_max_size = 500M
     memory_limit = 512M
     max_execution_time = 300
     max_input_time = 300
     ```
   - Symlink `public/storage -> /var/www/vhosts/maiscapinhas.com.br/api.maiscapinhas.com.br/storage/app/public`
   - Diretórios graváveis: `storage/app/public/tv-ads`, `storage/app/public/tv/logos`, `storage/app/temp/chunks`.
4. Executar pós-deploy no diretório `api.maiscapinhas.com.br/`:
   ```bash
   composer install --no-dev --optimize-autoloader --no-interaction
   php artisan migrate --pretend   # conferir que nenhuma migration TV será reexecutada
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan route:list --path=api/v1/tv -v
   php artisan route:list --path=api/v1/admin/tv -v
   ```

## 3. Sequência de Implantação — Frontend (`erp.maiscapinhas.com.br`)

> **Atenção crítica (Divergências Não-TV):** antes de publicar um novo build gerado a partir da `main` + `recovery/production-tv-signage-frontend`, revisar também a branch separada `recovery/production-non-tv-divergences-frontend` (Conferência Kanban, QuebraCaixa, PdvDebug, OperacoesHistory), pois essas telas estão ativas no build atual de produção (`15/08/2026`) mas ainda não estão na `main`! Publicar o frontend sem reconciliar essas telas reverteria funcionalidades não relacionadas às TVs.

1. Garantir `.env` de build de produção com `VITE_API_URL=https://api.maiscapinhas.com.br/api/v1` (e remover qualquer `.env.production.local` de desenvolvimento).
2. Executar `npm ci && npm run build`.
3. Publicar `dist/` em `erp.maiscapinhas.com.br/` (recomendado em janela planejada limpar os >8.000 arquivos obsoletos acumulados em `assets/` após backup prévio, mantendo apenas o build ativo).

## 4. Validação Pós-Implantação (Smoke Read-Only)

- `GET https://api.maiscapinhas.com.br/api/v1/tv/playlist/{slug-de-teste}` retorna `200 OK` com JSON válido (`playlist` e `design_template`).
- Login no ERP (`https://erp.maiscapinhas.com.br`) como admin -> menu **TVs & Anúncios → Anúncios de Loja** carrega Grade, TVs, Estúdio de Design, Estatísticas e Logs sem erros `4xx`/`5xx`.
- Verificar `storage/logs/laravel.log` e `logs/api.maiscapinhas.com.br/error_log` por 10 minutos sem novos erros.
