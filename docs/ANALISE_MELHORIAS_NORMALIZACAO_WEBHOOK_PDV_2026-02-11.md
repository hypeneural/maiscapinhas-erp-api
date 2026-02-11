# Analise de Melhorias - Normalizacao Lojas/Usuarios no Webhook PDV

Data: 2026-02-11  
Base analisada: respostas tecnicas do time PDV Sync Agent (`schema 2.0`)

## 1) O que ficou claro (confirmado)

1. `id_ponto_venda` vem de configuracao local (`.env`) e pode nao ser globalmente unico na rede.
2. `id_usuario` e local por loja (nao global), entao a chave correta de usuario e composta por loja.
3. `operador.id_usuario` (turno) e `vendedor.id_usuario` (item) apontam para a mesma tabela de usuarios, com papeis diferentes.
4. O agente e incremental por janela e nao corrige historico fora da janela (ex.: cancelamento retroativo).
5. `id_finalizador` e local por loja; para comparacao entre lojas, precisa normalizacao composta.
6. `codigo_barras` tende a ser a melhor chave canonica para produto em visao global.

## 2) Riscos reais para o backend

## 2.1 Colisao de identidade de loja (alto)

Se duas lojas enviarem o mesmo `id_ponto_venda`, o mapping atual por ID unico pode associar sync em loja errada.

Impacto:
- distorcao de fechamento de caixa;
- metas/ranking atribuidos para loja errada;
- contaminacao de historico financeiro.

## 2.2 Ambiguidade de usuario entre lojas (alto)

`id_usuario` sozinho nao identifica pessoa na rede.

Impacto:
- vendedor misturado entre lojas;
- consolidacao incorreta de metas/comissao;
- relatorios por vendedor inconsistentes.

## 2.3 Falta de evento retroativo (medio/alto)

Cancelamentos ou edicoes apos janela nao voltam no webhook.

Impacto:
- divergencia silenciosa entre ERP e backend;
- necessidade de reconciliacao periodica para confianca contabil.

## 2.4 Dicionarios locais por loja (medio)

`id_finalizador` e `id_produto` podem variar por loja.

Impacto:
- filtros globais quebram se usar apenas ID local;
- comparacao interlojas exige chaves compostas/canonicas.

## 3) Melhorias recomendadas no backend (prioridade)

## P0 - obrigatorio antes do rollout completo

1. Formalizar chave de loja temporaria como `id_ponto_venda + store.alias` ate existir `store_external_id`.
2. Adicionar deteccao de divergencia de identidade na ingestao (alias inesperado -> `risk_flag` + opcao de bloqueio).
3. Implementar `pdv_user_mappings` com chave composta (`store_pdv_id`, `pdv_user_id`).
4. Padronizar regras de vendedor null (`unassigned` ou regra de negocio definida por produto).
5. Garantir que qualquer consolidacao por usuario use chave composta, nunca `id_usuario` isolado.

## P1 - alta prioridade

1. Definir reconciliacao retroativa (job diario/semanal) para detectar cancelamentos e divergencias.
2. Implementar governanca de conflito (`status=blocked` vs `risk_flags`) para loja/usuario.
3. Criar dicionarios normalizados para:
- pagamento: (`store_pdv_id`, `id_finalizador`)
- produto: `codigo_barras` como chave canonica global
4. Solicitar carga inicial oficial de usuarios por loja e rotina de atualizacao.

## P2 - evolucao

1. Introduzir `store_external_id` no payload para chave global imutavel de loja.
2. Introduzir `user_external_id` no payload para identidade cross-loja.
3. Criar fluxo de registro inicial automatizado de loja (`register handshake`).

## 4) Regras de normalizacao recomendadas (estado alvo)

1. Loja canonica: `store_external_id` (quando existir).  
Fallback atual: `id_ponto_venda + store.alias`.

2. Usuario canonico: `user_external_id` (quando existir).  
Fallback atual: (`store_pdv_id`, `id_usuario`).

3. Pagamento canonico: (`store_pdv_id`, `id_finalizador`) + nome para auditoria.

4. Produto canonico global: `codigo_barras`.  
`id_produto` deve ser tratado como identificador local da loja.

## 5) Pendencias para fechar com o time ERP

1. Confirmar se conseguem entregar `store_external_id` e `user_external_id`.
2. Confirmar politica de alteracao retroativa e roadmap de evento de cancelamento pos-envio.
3. Confirmar carga inicial de usuarios por loja (id, nome, status).
4. Definir SLA para onboarding de nova loja antes de iniciar envio real.

## 6) Criterio de pronto (normalizacao)

Pronto quando:
- existe contrato escrito de identidade de loja e usuario;
- mapping de usuario PDV -> usuario interno esta implementado e auditavel;
- colisao de identidade de loja nao passa silenciosamente;
- estrategia de reconciliacao retroativa esta ativa;
- filtros interlojas usam chaves corretas (compostas/canonicas).

