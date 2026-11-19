# TV Digital Signage — Mais Capinhas ERP

Documentação técnica oficial do módulo de **TV Digital Signage** integrado ao ERP Mais Capinhas, recuperado forensicamente do ambiente de produção (`2026-11-19`) e reconciliado com o repositório oficial do GitHub.

## Índice da Documentação

| Documento | Conteúdo |
| --- | --- |
| [ARCHITECTURE.md](./ARCHITECTURE.md) | Arquitetura backend/frontend, fluxo ponta a ponta de mídia, dependências compartilhadas e integração em tempo real |
| [API_CONTRACT.md](./API_CONTRACT.md) | Contrato completo dos **19 endpoints** (`/api/v1/tv/*` e `/api/v1/admin/tv/*`), parâmetros, validações e respostas |
| [DATABASE_SCHEMA.md](./DATABASE_SCHEMA.md) | Esquema das **7 tabelas `tv_*`**, as **5 migrations históricas**, chaves estrangeiras, índices e auditoria de drift |
| [PLAYER_RUNTIME.md](./PLAYER_RUNTIME.md) | Ciclo de execução do player `/tv/:slug`, cache local (`Cache Storage`), overlays, blackout, telemetria e anomalias |
| [ADMIN_FLOW.md](./ADMIN_FLOW.md) | Fluxos operacionais do painel `/admin/tv` (Grade, TVs, Estúdio de Design, Estatísticas & Uptime e Logs) |
| [SECURITY_AUDIT.md](./SECURITY_AUDIT.md) | Auditoria de segurança (autorização, upload em chunks, `media_path`, endpoints públicos, CORS, CSP e logs) |
| [TEST_PLAN.md](./TEST_PLAN.md) | Estratégia de testes automatizados (PHPUnit/Pest, TypeScript, ESLint, Vite build) e QA isolado em navegador |
| [DEPLOY_RUNBOOK.md](./DEPLOY_RUNBOOK.md) | Procedimento seguro de merge, sincronização no Plesk, limites PHP (`.user.ini`) e validação pós-implantação |
| [ROLLBACK_RUNBOOK.md](./ROLLBACK_RUNBOOK.md) | Procedimento seguro e não destrutivo de reversão (backend e frontend) preservando banco e mídias |
| [IMPROVEMENT_BACKLOG.md](./IMPROVEMENT_BACKLOG.md) | Backlog priorizado **P0–P4** para estabilização, performance e evolução futura do sistema |

## Visão Rápida dos Repositórios e Branches de Recuperação

- **Backend (`hypeneural/maiscapinhas-erp-api`):** branch `recovery/production-tv-signage-backend` (base `main@fab00a80`)
- **Frontend (`hypeneural/maiscapinhas-7d425b09`):** branch `recovery/production-tv-signage-frontend` (base `main@de141f32`)
- **Dependência entre PRs:** Backend deve ser revisado e implantado antes (ou simultaneamente) ao Frontend para garantir presença das 19 rotas `/api/v1/*tv*` e da permissão `screen.tv.ads` em `/api/v1/me`.
