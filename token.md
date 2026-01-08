# 🔐 API Token para Testes

## Credenciais

| Campo | Valor |
|-------|-------|
| **Email** | admin@maiscapinhas.com.br |
| **Nome** | Admin Sistema |
| **User ID** | 1 |

## Bearer Token

```
2|yPNqTWnttSq6X9uKnWp6XhplfRLzzmI0yXhdr0Csf019bda1
```

## Como usar

### Header de Autenticação

```http
Authorization: Bearer 2|yPNqTWnttSq6X9uKnWp6XhplfRLzzmI0yXhdr0Csf019bda1
Accept: application/json
Content-Type: application/json
```

### Exemplo cURL

```bash
curl https://api.maiscapinhas.com.br/api/v1/me \
  -H "Authorization: Bearer 2|yPNqTWnttSq6X9uKnWp6XhplfRLzzmI0yXhdr0Csf019bda1" \
  -H "Accept: application/json"
```

---
**Gerado em:** 2026-01-08 01:10:52
