# API Bio - Guia de Integração Frontend

Documentação para integração do site agregador Bio com a API de lojas.

---

## Endpoint Principal

```
GET https://api.maiscapinhas.com.br/api/v1/bio/stores
```

> [!NOTE]
> Este endpoint é **público** e não requer autenticação.

---

## Estrutura da Resposta

```json
{
  "data": [ /* array de lojas */ ],
  "meta": { "total": 1 }
}
```

---

## Campos de Cada Loja

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `id` | number | ID único da loja |
| `name` | string | Nome da loja |
| `city` | string | Cidade |
| `photo_url` | string\|null | URL da foto da fachada |
| `address` | string\|null | Endereço |
| `neighborhood` | string\|null | Bairro |
| `state` | string\|null | Estado (UF) |
| `zip_code` | string\|null | CEP |
| `full_address` | string\|null | Endereço formatado completo |
| `latitude` | number\|null | Coordenada GPS |
| `longitude` | number\|null | Coordenada GPS |
| `phone` | string\|null | Telefone |
| `whatsapp` | string\|null | WhatsApp (formato: 5548999999999) |
| `instagram` | string\|null | Username do Instagram |
| `opening_hours` | object | Horários brutos (JSON) |
| `hours_human` | object | **Horários calculados para exibição** |

---

## Horários de Funcionamento

### Campos do `hours_human` (USAR NO FRONTEND)

| Campo | Tipo | Quando Usar |
|-------|------|-------------|
| `is_open_now` | boolean | Badge "Aberto"/"Fechado" |
| `status` | string | `"open"`, `"closed"`, `"unknown"` |
| `status_label` | string | **Texto principal para exibir** |
| `today_hours_label` | string | Resumo do horário de hoje |
| `weekly_label` | string | Resumo semanal |
| `opens_at` | string\|null | Próximo horário de abertura (se fechado) |
| `closes_at` | string\|null | Horário de fechamento (se aberto) |
| `next_change_at` | string\|null | ISO 8601 do próximo evento |
| `timezone` | string | Timezone (ex: `America/Sao_Paulo`) |

### Exemplos de `status_label`

| Situação | status_label |
|----------|--------------|
| Aberto | `"Aberto agora • Fecha às 21:00"` |
| Fechado, abre hoje | `"Fechado • Abre às 14:00"` |
| Fechado, não abre mais | `"Fechado • Não abre mais hoje"` |
| Dia fechado | `"Fechado hoje"` |
| Sem horário | `"Horário não informado"` |

---

## Implementação Recomendada

### React/Next.js

```tsx
interface Store {
  id: number;
  name: string;
  city: string;
  photo_url: string | null;
  whatsapp: string | null;
  instagram: string | null;
  hours_human: {
    is_open_now: boolean;
    status: 'open' | 'closed' | 'unknown';
    status_label: string;
    today_hours_label: string;
    weekly_label: string;
  };
}

async function fetchStores(): Promise<Store[]> {
  const res = await fetch('https://api.maiscapinhas.com.br/api/v1/bio/stores');
  const { data } = await res.json();
  return data;
}
```

### Componente de Card

```tsx
function StoreCard({ store }: { store: Store }) {
  const { hours_human } = store;
  
  return (
    <div className="store-card">
      {store.photo_url && <img src={store.photo_url} alt={store.name} />}
      
      <h2>{store.name}</h2>
      <p>{store.city}</p>
      
      {/* Badge Aberto/Fechado */}
      <span className={hours_human.is_open_now ? 'badge-open' : 'badge-closed'}>
        {hours_human.is_open_now ? '🟢 Aberto' : '🔴 Fechado'}
      </span>
      
      {/* Status detalhado */}
      <p className="status">{hours_human.status_label}</p>
      
      {/* Horário de hoje */}
      <p className="today">{hours_human.today_hours_label}</p>
      
      {/* Resumo semanal (para expandir) */}
      <details>
        <summary>Ver horários da semana</summary>
        <p>{hours_human.weekly_label}</p>
      </details>
      
      {/* WhatsApp */}
      {store.whatsapp && (
        <a href={`https://wa.me/${store.whatsapp}`}>
          Falar no WhatsApp
        </a>
      )}
    </div>
  );
}
```

---

## CSS Sugerido

```css
.badge-open {
  background: #22c55e;
  color: white;
  padding: 4px 12px;
  border-radius: 9999px;
}

.badge-closed {
  background: #ef4444;
  color: white;
  padding: 4px 12px;
  border-radius: 9999px;
}

.status {
  font-weight: 600;
  color: #374151;
}

.today {
  color: #6b7280;
  font-size: 0.875rem;
}
```

---

## Filtrar por Cidade

```
GET /api/v1/bio/stores?city=Tijucas
```

---

## Loja Individual

```
GET /api/v1/bio/stores/{id}
```

Retorna a mesma estrutura, mas com um único objeto em `data` ao invés de array.

---

## Formato do WhatsApp

O campo `whatsapp` vem no formato `5548999999999` (código país + DDD + número).

Para criar link:
```js
const whatsappLink = `https://wa.me/${store.whatsapp}`;
```

---

## Checklist de Implementação

- [ ] Fetch das lojas no carregamento
- [ ] Exibir badge Aberto/Fechado usando `is_open_now`
- [ ] Mostrar `status_label` como texto principal
- [ ] Mostrar `today_hours_label` como horário do dia
- [ ] Opcional: `weekly_label` em seção expandível
- [ ] Link WhatsApp com `wa.me/{whatsapp}`
- [ ] Link Instagram com `instagram.com/{instagram}`
- [ ] Fallback para campos null (não exibir se vazio)
