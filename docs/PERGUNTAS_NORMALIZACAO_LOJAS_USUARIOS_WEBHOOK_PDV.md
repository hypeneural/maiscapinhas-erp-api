# Perguntas Tecnicas - Normalizacao de Lojas e Usuarios (Webhook PDV JSON)

Data: 2026-02-11  
Projeto: `maiscapinhas-erp-api`  
Destino: time que gera/envia o JSON do ERP Hiper (PDV Sync Agent)

## 1) Objetivo

Fechar o contrato de identidade dos dados no webhook para evitar:
- vendedor duplicado ou trocado em relatorios;
- loja mapeada incorretamente;
- perda de rastreabilidade em ajustes retroativos;
- inconsistencias em metas, fechamento de caixa e filtros de vendas.

## 2) Contexto tecnico atual do backend (para alinhamento)

- Recebemos `store.id_ponto_venda` e mapeamos para loja interna via `pdv_store_mappings`.
- Persistimos `operador_pdv_id` (turno) e `vendedor_pdv_id` (item), mas ainda sem tabela oficial de mapeamento de usuarios PDV -> usuarios internos.
- Processamos webhook com idempotencia (`sync_id`, `id_operacao`, `line_id/row_hash`) e fila Redis.
- Quando mapping de loja nao existe, aceitamos o sync e marcamos `risk_flag=store_mapping_missing`.

## 3) Perguntas P0 (bloqueantes para rollout completo)

## 3.1 Identidade de loja (`store.id_ponto_venda`)

1. `id_ponto_venda` e globalmente unico em toda a rede ou apenas unico por banco local?
2. `id_ponto_venda` pode mudar apos reinstalacao/migracao de banco da loja?
3. `id_ponto_venda` pode ser reutilizado para outra loja no futuro?
4. Existe algum identificador imutavel melhor que `id_ponto_venda` (ex.: GUID da loja, CNPJ, codigo legado)?
5. `store.nome` e `store.alias` sao apenas display ou podem ser usados como chave de negocio?
6. Quando uma loja muda nome/alias, isso muda retroativamente nos payloads futuros?
7. Existe evento formal de abertura/fechamento/renomeacao de loja que possamos consumir?
8. A rede pode ter 2 lojas com mesmo `store.nome` em regioes diferentes?
9. Existe timezone por loja diferente de `America/Sao_Paulo` hoje ou no roadmap?
10. Qual o SLA para aviso de nova loja antes de comecar a enviar webhook?

## 3.2 Identidade de usuario (`operador.id_usuario`, `itens[].vendedor.id_usuario`)

11. `id_usuario` e globalmente unico entre lojas ou unico apenas dentro de cada loja?
12. A mesma pessoa pode ter IDs diferentes em lojas diferentes?
13. Um mesmo `id_usuario` pode representar pessoas diferentes em lojas distintas?
14. Existe identificador central de pessoa (matricula/CPF/e-mail) que possa ser enviado no payload?
15. `operador.id_usuario` (turno) e sempre o mesmo conceito de `vendedor.id_usuario` (item)?
16. Um operador pode abrir turno e outro vendedor vender no mesmo turno? (regra oficial)
17. `id_usuario` pode ser reciclado apos desligamento/reativacao?
18. Alteracao de nome de usuario ocorre com frequencia? Existe historico oficial?
19. Quando `id_usuario` for null, qual regra oficial devemos aplicar para metas/comissao?
20. Existe tabela mestre de usuarios por loja que voces possam exportar periodicamente?

## 3.3 Correcao retroativa e consistencia historica

21. Venda/item ja enviado pode mudar vendedor depois? Se sim, em qual evento?
22. Turno fechado pode ser reaberto e alterar operador/totais?
23. Se houver correcao retroativa, o agente reenviara o mesmo `id_operacao` com novos dados?
24. Qual o comportamento oficial para cancelamento apos envio (novo evento vs ajuste no registro)?
25. Existem casos conhecidos de divergencia entre `resumo.by_vendor` e soma real de `vendas[].itens[]`?

## 4) Perguntas P1 (alta prioridade)

## 4.1 Dicionarios de apoio para normalizacao

26. Podem enviar carga inicial oficial de lojas (`id_ponto_venda`, nome, alias, status)?
27. Podem enviar carga inicial oficial de usuarios por loja (`id_usuario`, nome, status, papel)?
28. Qual campo define usuario ativo/inativo?
29. Em quanto tempo uma mudanca cadastral entra no payload?
30. Podem publicar endpoint/arquivo de referencia para reconciliacao diaria de cadastro?

## 4.2 Pagamentos e produto (impacto em filtros)

31. `id_finalizador` e estavel por loja ao longo do tempo?
32. `id_finalizador` pode apontar para nomes diferentes em lojas diferentes?
33. Em caso de reconfiguracao de finalizador, existe evento de versao da tabela?
34. `id_produto` e estavel entre lojas ou somente local por loja?
35. `codigo_barras` no payload e sempre o mesmo cadastro usado em todas as lojas?

## 5) Perguntas P2 (operacao e governanca)

36. Qual contato tecnico para emergencias de mapping (loja/usuario) em producao?
37. Qual SLA de resposta para incidentes de dados inconsistentes?
38. Como sera comunicado breaking change de identificadores (loja/usuario/finalizador)?
39. Podem fornecer massa de teste com casos de borda de normalizacao (usuario duplicado, loja renomeada, vendedor null)?
40. Existe roadmap para incluir `user_external_id` e `store_external_id` no schema?

## 6) Decisoes que precisamos fechar por escrito

1. Chave canonica de loja para mapping definitivo.
2. Chave canonica de usuario para metas/comissoes.
3. Regra oficial para `id_usuario` null.
4. Politica oficial de alteracao retroativa (aceita/rejeita/reprocessa).
5. Fonte oficial da verdade para cadastro de lojas e usuarios.

## 7) Entregaveis solicitados ao time ERP

1. Documento de contrato de identidade (loja/usuario/finalizador).
2. Carga inicial de lojas com IDs oficiais.
3. Carga inicial de usuarios por loja com status.
4. Lista de eventos de correcao retroativa suportados.
5. Exemplos JSON reais para 6 cenarios de borda:
- loja sem mapping previo;
- vendedor null;
- mesma pessoa em duas lojas;
- troca de nome de usuario;
- turno reaberto;
- cancelamento apos envio.

## 8) Template de resposta (preencher)

- Pergunta:
- Resposta oficial:
- Exemplo JSON/DB:
- Impacto tecnico esperado:
- Data de disponibilidade:
- Responsavel:

