# Análise Detalhada: Validação de Vendas e Divergências

Este documento detalha o funcionamento do endpoint de validação de vendas (`/api/v1/pdv/sales/validate`) e explica as causas comuns para status "Divergente" e as correções aplicadas.

## 1. Lógica de Validação

O sistema valida uma venda em 4 etapas:

1.  **Resolução de Loja**: Identifica o ID da loja (`store_pdv_id`) baseada no nome vindo do JSON.
2.  **Busca de Candidatos**: Procura no banco de dados vendas que coincidam com:
    *   **Loja**: Mesma loja identificada.
    *   **Total**: Valor total líquido (com tolerância de R$ 0,05).
    *   **Horário**: Dentro de uma janela de tempo (padrão: -10min a +2h do horário do ERP).
3.  **Comparação de Assinatura (100% Match)**: Se candidatos são encontrados, o sistema gera uma "assinatura" comparando:
    *   **Itens**: Lista de produtos (Código, Quantidade, Valor) ordenada.
    *   **Pagamentos**: Lista de pagamentos (Meio, Valor) ordenada.
4.  **Resultado**:
    *   **Match 100%**: Se a assinatura do ERP for *idêntica* à do Banco.
    *   **Divergente**: Se encontrou a venda (pelo Total/Horário) mas os itens ou pagamentos diferem.

## 2. Diagnóstico: Por que ocorria "Divergente"?

Durante os testes, identificamos que vendas existentes estavam retornando como "Divergentes" (Found=True, Match 100%=False). A investigação revelou as seguintes causas:

### A. Erro no Carregamento de Relacionamentos (Causa Técnica Principal)
O banco de dados do PDV usa uma chave composta (`store_pdv_id` + `id_operacao`) para relacionar Vendas com Itens e Pagamentos.
*   **Problema**: O ORM padrão (Eloquent) não suporta nativamente o carregamento automático (`with()`) para chaves compostas complexas dessa estrutura legada.
*   **Sintoma**: O sistema encontrava a venda (cabeçalho), mas ao tentar ler os itens, o ORM retornava uma lista vazia `[]`.
*   **Consequência**: O validador comparava "Lista de Itens do ERP" (cheia) com "Lista de Itens do Banco" (vazia), resultando em divergência.
*   **Correção**: O `PdvSaleValidator` foi ajustado para fazer o carregamento manual (`setRelation`) usando as chaves corretas.

**Resultado após correção:**
```
[5] Testando Loja 7 (Esperado: Found=true, Match 100%=SIM)...
    [SUCCESS] Encontrado!
    Match 100%: SIM
```

### B. Mapeamento de Lojas Ausente
Algumas lojas (ex: "Loja 7 - Bombinhas", "Loja 5 - Komprão") não estavam no mapa de conversão `Nome -> ID` do validador.
*   **Sintoma**: O sistema retornava "Não conseguimos resolver store_pdv_id".
*   **Correção**: Foram adicionados mapeamentos de fallback para estas lojas.

### C. Vendas Canceladas
Vendas canceladas no ERP aparecem no JSON com a flag `"Cancelada": true`.
*   **Comportamento**: O validador agora checa essa flag antes de buscar no banco. Se for verdadeira, retorna `status_erp: "CANCELLED"`, evitando alertas falsos de "Venda não encontrada".

## 3. Matriz de Resultados Possíveis

| Cenário | Resultado API | Significado | Ação Recomendada |
| :--- | :--- | :--- | :--- |
| **Sucesso Total** | `found: true`, `match_100: true` | Venda sincronizada perfeitamente. | Nenhuma (OK). |
| **Divergência** | `found: true`, `match_100: false` | Venda existe, mas valores internos diferem. | Verificar se houve edição manual no ERP ou falha de sync parcial. |
| **Não Encontrada** | `found: false` | Nenhuma venda com aquele total/horário na loja. | Verificar se o sync rodou ou se o Timezone está correto. |
| **Cancelada** | `status_erp: "CANCELLED"` | Venda foi cancelada no PDV. | Ignorar. |

## 4. Testes Realizados (Robustez)

Validamos o sistema com cargas reais extraídas dos logs:

1.  **Loja 12 (Porto Belo)**: Venda simples.
2.  **Loja 4 (iTuntz)**: Venda com múltiplos meios de pagamento.
3.  **Loja 5 (Komprão)**: Venda com múltiplos itens.
    *   *Status*: Validado Match 100%.
4.  **Loja 7 (Bombinhas)**: Venda divergente investigada (ID 458).
    *   *Status*: Validado Match 100% após correção de relacionamento.

O sistema agora está robusto para validar a integridade dos dados entre ERP e Banco de Dados.
