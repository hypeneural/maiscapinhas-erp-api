# Documentação Frontend: Validação de Vendas ERP

Esta documentação descreve como implementar a interface para a nova funcionalidade de **Validação de Vendas ERP vs. Banco de Dados**.

## Objetivo da Página
Permitir que um administrador ou desenvolvedor cole o JSON de uma venda (extraído do ERP ou logs) e verifique instantaneamente se ela existe no banco de dados e se os dados (Totais, Itens e Pagamentos) batem 100%.

---

## Detalhes do Endpoint

- **URL**: `/api/v1/pdv/sales/validate`
- **Método**: `POST`
- **Autenticação**: Requer Header `Authorization: Bearer <TOKEN>` (mesmo token de admin logado).

---

## Schema da Requisição

O corpo da requisição deve ser enviado como JSON.

```json
{
  "payload": "{ ... JSON CORPO DA VENDA DO ERP ... }", 
  "timezone": "America/Sao_Paulo",
  "tolerance": {
    "total": 0.05,
    "start_minus_minutes": 10,
    "end_plus_minutes": 120
  }
}
```

### Campos:
| Campo | Tipo | Obrigatório? | Descrição |
|-------|------|--------------|-----------|
| `payload` | String ou Object | **Sim** | O JSON da venda. Pode ser enviado como string (conteúdo de um textarea) ou objeto JSON parseado. |
| `timezone` | String | Não | Timezone para interpretar a data do ERP. Default: `America/Sao_Paulo`. |
| `tolerance` | Object | Não | Configurações avançadas de tolerância (opcional). |

---

## Schema da Resposta

A resposta indica se a venda foi encontrada (`found`) e se os dados batem perfeitamente (`match_100`).

### Exemplo de Sucesso (Encontrado e Igual)

```json
{
  "ok": true,
  "found": true,
  "match_100": true,
  "best_match": {
    "pdv_venda_id": 12345,
    "id_operacao_db": 998877,
    "erp_id_orig": 555444,
    "data_hora_utc": "2026-02-14T14:30:00+00:00",
    "total": 150.50,
    "items_exact": true,
    "payments_exact": true,
    "match_100": true,
    "db_details": {
        "store_db": {
            "id": 7,
            "nome_hiper": "Loja 5 - MC Komprão BR Tijucas"
        },
        "user_db": {
            "nome": "Loja 5 - Komprão BR/Tijucas",
            "login": "tijucas3",
            "user_id": 101
        },
        "timestamps": {
            "data_venda": "2026-02-14T14:30:00+00:00",
            "created_at": "2026-02-14T14:32:01+00:00",
            "updated_at": "2026-02-14T14:32:01+00:00",
            "last_seen": "2026-02-14T14:40:05+00:00"
        },
        "identifiers": {
            "id_operacao": 998877,
            "id_turno": "UUID-DO-TURNO",
            "pdv_venda_id": 12345
        }
    },
    "signatures": {
       "erp_items": [ ... ],
       "db_items": [ ... ]
    }
  },
  "all_candidates_count": 1,
  "search": { ... }
}
```

### Exemplo: Encontrado mas Divergente (Atenção)

```json
{
  "ok": true,
  "found": true,
  "match_100": false,
  "best_match": {
    "pdv_venda_id": 12345,
    "items_exact": false,  // Itens diferentes!
    "payments_exact": true
  }
}
```

### Exemplo: Venda Cancelada (Aviso)

```json
{
  "ok": true,
  "found": false,
  "match_100": false,
  "reason": "Venda está CANCELADA no ERP.",
  "status_erp": "CANCELLED"
}
```

### Exemplo: Não Encontrado (Erro)

```json
{
  "ok": true,
  "found": false,
  "match_100": false,
  "reason": "Nenhuma venda candidata encontrada (loja+total+janela).",
  "search": {
     "window_utc": ["2026-02-14T18:12:00", "2026-02-14T20:22:00"]
  }
}
```

---

## Sugestões de Implementação (UI/UX)

### 1. Layout Básico
- **Textarea Grande**: Ocupando 50% da tela, rotulado "Cole o JSON da Venda ERP aqui".
- **Botão de Ação**: "Validar Venda" (Chamar API no onClick).
- **Indicador de Loading**: Enquanto a requisição processa.

### 2. Exibição de Resultados (Semáforo)

Recomendo usar um card de resultado que muda de cor conforme o status:

- 🟢 **Verde (Sucesso Total)**:
  - `found: true` E `match_100: true`
  - Mensagem: "Venda Sincronizada com Sucesso!"
  - Mostrar ID da Venda (`pdv_vendas.id`) e Link para ver detalhes se possível.

- 🟡 **Amarelo (Antenção - Dados Divergentes)**:
  - `found: true` MAS `match_100: false`
  - Mensagem: "Venda Encontrada, mas com diferenças."
  - **Ação**: Mostrar o que diferiu.
    - Se `items_exact: false` -> "Itens não batem".
    - Se `payments_exact: false` -> "Pagamentos não batem".

- 🔴 **Vermelho (Não Encontrado)**:
  - `found: false`
  - Mensagem: "Venda NÃO encontrada no Banco de Dados."
  - Mostrar detalhes da busca (`search`): Janela de tempo usada, Total buscado, Loja ID.
  - Ajuda a debugar se é problema de Timezone ou Delay de Sync.

### 3. Tratamento de Erros
- Se a API retornar erro 400 ou 422 (JSON inválido), mostrar alerta: "JSON Inválido ou Mal Formado".
- Se `ok: false` na resposta, mostrar a mensagem de `error`.

## Exemplo de JSON para Teste

Use este JSON para testar o layout (Loja 12 - Sucesso):

```json
{
    "CodigoDaOperacao": 297556,
    "Data": "2026-02-14T15:22:04",
    "ValorTotalLiquido": 35.00,
    "Loja": { "Nome": "Loja 12 - MC Porto Belo" },
    "Itens": [
        { "Codigo": "1234", "Quantidade": 1, "ValorTotalLiquido": 35.00 }
    ],
    "MeiosDePagamentosAgrupados": [
        { "MeiosDePagamentos": [ { "Descricao": "Dinheiro", "Valor": 35.00 } ] }
    ]
}
```
