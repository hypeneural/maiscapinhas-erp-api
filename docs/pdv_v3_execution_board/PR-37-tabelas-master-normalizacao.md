# PR-37 - Tabelas master e auto cadastro

Status: `done`  
Prioridade: `P1`  
Dependencias: PR-32, PR-33, PR-36

Observacao: validado com suite dedicada do job (`tests/Unit/Jobs/ProcessPdvSyncJobMasterDataTest.php`).

## Objetivo
Criar camada de normalizacao (`pdv_lojas`, `pdv_usuarios`, `pdv_meios_pagamento`) com auto cadastro durante sync.

## Escopo in
- Migrations das tabelas master.
- Models e upsert automatizado.
- Regras de preenchimento inicial.

## Escopo out
- UI administrativa de manutencao manual (pode vir depois).

## Checklist tecnico

## 1) Migrations
- [x] Criar `pdv_lojas`.
- [x] Criar `pdv_usuarios`.
- [x] Criar `pdv_meios_pagamento`.
- [x] Definir chaves unicas:
- [x] `pdv_lojas.id_ponto_venda`
- [x] `pdv_usuarios.id_usuario_hiper`
- [x] `pdv_meios_pagamento.id_finalizador`
- [x] Adicionar timestamps e campos de origem.

## 2) Models
- [x] Criar models Eloquent para as 3 tabelas.
- [x] Definir casts basicos e fillable.

## 3) Auto cadastro no processamento
- [x] Loja:
- [x] upsert com `store.id_ponto_venda`, `store.nome`, `store.alias`.
- [x] Usuarios:
- [x] upsert de `operador`, `responsavel`, `vendedor`.
- [x] Meios de pagamento:
- [x] upsert de finalizadores encontrados em turnos e vendas.
- [x] Estrategia de nome:
- [x] `nome_hiper` atualizado sempre.
- [x] `nome_padronizado` inicial via first create.

## 4) Testes
- [x] Auto cadastro de loja sem duplicar.
- [x] Auto cadastro de usuario em vendedor e operador.
- [x] Auto cadastro de meio pagamento.
- [x] Update de `nome_hiper` preservando `nome_padronizado`.

## 5) Documentacao
- [ ] Atualizar docs de arquitetura de dados PDV.
- [ ] Documentar estrategia de padronizacao manual posterior.

## Criterio de aceite
- Sync real passa a popular dimensoes master automaticamente.

## Arquivos alvo esperados
- `database/migrations/*_create_pdv_lojas_table.php`
- `database/migrations/*_create_pdv_usuarios_table.php`
- `database/migrations/*_create_pdv_meios_pagamento_table.php`
- `app/Models/PdvLoja.php`
- `app/Models/PdvUsuario.php`
- `app/Models/PdvMeioPagamento.php`
- `app/Jobs/ProcessPdvSyncJob.php`
- `tests/*`

## Riscos e mitigacoes
- Risco: sujeira de nomenclatura no cadastro automatico.
- Mitigacao: separar nome original e nome padronizado.

## Validacao manual sugerida
- [ ] Enviar payload de loja nova.
- [ ] Conferir criacao em `pdv_lojas`.
- [ ] Conferir vendedores em `pdv_usuarios`.
