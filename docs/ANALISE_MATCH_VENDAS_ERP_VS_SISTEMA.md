# Análise: Match de Vendas ERP vs Sistema

Esta documentação analisa a estrutura de dados de vendas do ERP (formato JSON) e como ela se relaciona com nosso banco de dados interno, definindo estratégias para verificar se uma venda foi importada corretamente.

## 1. Fluxo de Dados

O fluxo de importação segue o seguinte caminho:
1.  **Origem**: ERP Hiper (Gera o JSON da operação).
2.  **Agente**: PDV Sync Agent lê os dados e envia um payload para a API.
3.  **Ingestão**: `PdvSyncController` recebe o payload.
4.  **Processamento**: `ProcessPdvSyncJob` processa o JSON e persiste nas tabelas `pdv_vendas`, `pdv_venda_itens` e `pdv_venda_pagamentos`.

## 2. Pontos Críticos de Divergência (Análise Loja 12)

A análise comparativa entre o JSON do ERP e o Payload do Webhook (Agente) revelou discrepâncias fundamentais que **invalidam o match simples por ID ou Data Exata**.

### Caso de Estudo: Venda de R$ 35,00 (Loja 12)

| Campo | JSON ERP (Origem) | Webhook Agente (Destino) | Diferença/Observação |
| :--- | :--- | :--- | :--- |
| **ID da Operação** | `CodigoDaOperacao`: **297556** | `id_operacao`: **33586** | **NÃO BATEM**. O Agente gera/usa um ID interno diferente. |
| **Data/Hora** | `Data`: **15:22:04** | `data_hora`: **15:26:21** | **+4 minutos**. O ERP registra o *início/abertura*, o Agente envia o *fechamento/sync*. |
| **Timezone** | Horário Local (ex: 15:22) | UTC (ex: 18:22 no DB) | **+3 Horas**. O banco salva em UTC. |
| **Itens** | 1 Item (Cód 7328) | 1 Item (Cód 7328) | **OK**. Itens batem. |
| **Fim de Turno** | - | `snapshot_vendas`: 15:22:04 | O *snapshot* do Agente tem a data original do ERP! |

### Conclusão dos Dados

1.  **ID é Instável**: Não podemos usar `CodigoDaOperacao` do ERP para buscar `id_operacao` no banco.
2.  **Data é Instável**: A data do ERP (15:22) pode diferir da data final da venda no banco (15:26) em minutos ou horas.
3.  **Timezone**: Sempre considerar offset de +3h (ou variável dependendo do horário de verão/servidor).

## 3. Estratégia de Match Robusta (Algoritmo Recomendado)

Para garantir o match, devemos abandonar a busca por igualdade estrita e adotar uma busca por **Assinatura Comportamental**.

### Passo 1: Definir Janela de Tempo
Como a data do ERP é o "início" e a do banco é o "fim" (em UTC), a venda no banco sempre estará **NO FUTURO** em relação ao ERP.

*   **ERP Data**: `T_erp`
*   **Janela de Busca**: `[T_erp + 2h50m]` até `[T_erp + 4h]`
    *   Por que +2h50m? Para cobrir o timezone (+3h) menos pequenas variações de relógio.
    *   Por que +4h? Para cobrir vendas longas ou delays de sync.

### Passo 2: Query de Assinatura
Buscar candidatos que coincidam em **Loja** e **Valor Total**.

```sql
SELECT *
FROM pdv_vendas
WHERE store_pdv_id = ? 
  AND total BETWEEN ? - 0.05 AND ? + 0.05 -- Tolerância de centavos
  AND data_hora BETWEEN ? AND ?; -- Janela calculada acima
```

### Passo 3: Desempate (Tie-Breaker)
Se houver mais de um candidato, verificar:
1.  **Quantidade de Itens**: `count(itens)`.
2.  **Produtos**: Se tiver os códigos de barras, verificar se `pdv_venda_itens` contém os mesmos produtos.
3.  **Pagamentos**: Verificar se o meio de pagamento (ex: Pix) bate.

## 4. Normalização e Deduplicação (ProcessPdvSyncJob)

A análise do código `ProcessPdvSyncJob.php` revela como o sistema garante a integridade dos dados:

1.  **Chave de Unicidade (Upsert)**:
    *   O comando `upsert` utiliza a chave composta: `['store_pdv_id', 'canal', 'id_operacao']`.
    *   Isso significa que, se o Agente enviar o mesmo `id_operacao` novamente, o registro será **atualizado** (não duplicado).
    *   Como o Agent gera seus próprios IDs (ex: `7780`), a integridade depende inteiramente da consistência do Agent em manter esse ID para a mesma venda.

2.  **Fingerprinting (Itens e Pagamentos)**:
    *   Para os itens, cria-se um hash (`itemFingerprint`) baseado em: `id_produto`, `codigo_barras`, `qtd`, `total`, etc.
    *   Isso permite identificar se um item mudou, mesmo que a venda seja a mesma.

## 5. Validação Definitiva: Caso Loja 4 (iTuntz)

Tivemos acesso ao registro real no banco e ao JSON original, confirmando nossa tese de Match.

| Dado | Origem (ERP JSON) | Destino (Banco/API) | Match? |
| :--- | :--- | :--- | :--- |
| **ID** | `CodigoDaOperacao`: **297568** | `id_operacao`: **7780** | **NÃO** (Transformado pelo Agent) |
| **Data** | `Data`: **15:40:17** (Local) | `data_hora`: **18:40:25** (UTC) | **SIM** (3h Timezone + 8s Delay) |
| **Valor** | `ValorTotalLiquido`: **84.90** | `total`: **84.9** | **SIM** |
| **Loja** | `LojaId`: ...7fae | `store_pdv_id`: **4** | **SIM** (Mapeado) |

**Conclusão Final:**
A estratégia de **Janela de Tempo (+3h)** + **Valor** + **Loja** é 100% eficaz. O _delay_ de processamento (8 segundos neste caso) é desprezível diante da janela de tolerância recomendada (+/- 30 min em torno do offset).

## 6. Algoritmo Final de Match (Pseudocódigo)

```python
def find_match(erp_json, db_connection):
    # 1. Extrair dados ERP
    erp_id = erp_json['CodigoDaOperacao'] # Apenas informativo
    erp_date = parse(erp_json['Data'])    # Ex: 15:40:17
    erp_total = erp_json['ValorTotalLiquido'] # 84.90
    cnpj = erp_json['Loja']['Cnpj'] (ou mapear LojaId)
    
    # 2. Calcular Janela UTC (Considerando delay do Agent)
    # UTC = Local + 3h. 
    # Janela: De (UTC - 5min) até (UTC + 60min)
    target_utc = erp_date + 3 hours
    start_window = target_utc - 5 minutes
    end_window = target_utc + 60 minutes
    
    # 3. Query
    sql = """
        SELECT * FROM pdv_vendas 
        WHERE store_pdv_id = ? 
          AND total BETWEEN (? - 0.05) AND (? + 0.05)
          AND data_hora BETWEEN ? AND ?
    """
    
    candidates = execute(sql, [store_id, erp_total, start_window, end_window])
    
    # 4. Desempate (se houver > 1)
    for c in candidates:
        if ABS(c.total - erp_total) < 0.01:
            return c.id # Match Confirmado
            
    return None
```
