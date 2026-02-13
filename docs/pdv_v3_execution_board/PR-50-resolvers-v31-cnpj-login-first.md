# PR-50 - Resolvers v3.1 (CNPJ first + Login first)

Status: `in_progress`  
Prioridade: `P0`  
Tipo: `backend-core`  
Dependencia: `PR-49`

## Objetivo
Aplicar a nova estrategia de identidade do agente v3.1 para reduzir `store_mapping_missing` e `user_mapping_missing`.

## Contexto
- Loja agora vem com `store.cnpj`.
- Usuario now vem com `login` em operador/responsavel/vendedor.
- Atualmente:
  - resolver de loja tenta alias/nome antes de CNPJ.
  - resolver de usuario ainda resolve por `pdv_user_id`.

## Escopo tecnico
- Mudar ordem do `PdvStoreResolver` para priorizar CNPJ.
- Evoluir `PdvUserResolver` para priorizar login.
- Manter fallback seguro com observabilidade.

## Tarefas
- [x] `PdvStoreResolver`:
- [x] Ordem nova: `cnpj` -> `pdv_store_id+alias` -> `pdv_store_id+nome` -> fallback por `pdv_store_id` unico.
- [x] Registrar `matched_by=cnpj` quando aplicavel.
- [x] Registrar risk flag `store_mapping_by_id_fallback` quando cair no fallback final.
- [x] `PdvUserResolver`:
- [x] Carregar indice por `pdv_user_login` (case-insensitive).
- [x] Resolver usuario por `login` quando presente no payload.
- [x] Fallback para `pdv_user_id` quando login ausente/nao mapeado.
- [x] Se mapping `is_store_operator=1`, retornar `null` sem `user_mapping_missing`.
- [x] `ProcessPdvSyncJob`:
- [x] Capturar `operador.login`, `responsavel.login`, `vendedor.login` do payload.
- [x] Chamar resolver com `(login, pdv_user_id)` na ordem correta.
- [x] Incluir risk flags novas:
- [x] `user_mapping_by_id_fallback`
- [x] `user_login_missing`
- [x] `user_login_mismatch` (quando login e id apontam para mappings divergentes)
- [x] Atualizar logs estruturados para incluir `pdv_user_login`.
- [x] Atualizar testes unitarios/feature para novos cenarios de binding.

## Criterios de aceite
- [ ] Sync com `store.cnpj` valido resolve loja por CNPJ, mesmo com alias errado.
- [ ] Sync com `vendedor.login` mapeado resolve `vendedor_user_id` sem depender de `id_usuario`.
- [ ] Fallback por id ocorre apenas quando login nao estiver disponivel e fica auditavel no risk flag.
- [ ] `user_mapping_missing` reduz nos cenarios com login presente.

## Verificacao manual
- [ ] Enviar webhook com alias propositalmente divergente e `store.cnpj` correto.
- [ ] Confirmar `store_id` correto e ausencia de `store_mapping_missing`.
- [ ] Enviar webhook com `vendedor.id_usuario` divergente e `vendedor.login` correto.
- [ ] Confirmar vinculacao ao usuario correto.

## Execucao realizada
- `app/Support/Pdv/PdvStoreResolver.php` atualizado para `cnpj` first.
- `app/Support/Pdv/PdvUserResolver.php` refeito com indices `by_id` e `by_login`.
- `app/Jobs/ProcessPdvSyncJob.php` atualizado para consumir `*.login` no binding de usuario.
- Novos flags de runtime adicionados no fluxo de resolucao de usuario.
- Testes unitarios adicionados:
  - `tests/Unit/Support/PdvStoreResolverTest.php`
  - `tests/Unit/Support/PdvUserResolverTest.php`
