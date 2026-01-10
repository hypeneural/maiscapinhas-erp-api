# User KPIs API - Guia de Integração Frontend

> **Endpoint**: `GET /api/v1/users/kpis`  
> **Autenticação**: Bearer Token (Sanctum)  
> **Versão**: v1

---

## 📋 Índice

1. [Visão Geral](#visão-geral)
2. [Endpoint e Autenticação](#endpoint-e-autenticação)
3. [Parâmetros de Filtro](#parâmetros-de-filtro)
4. [Schema JSON Completo](#schema-json-completo)
5. [Detalhamento dos Campos](#detalhamento-dos-campos)
6. [Exemplos de Requisições](#exemplos-de-requisições)
7. [Sugestões de UI/UX](#sugestões-de-uiux)
8. [Tratamento de Erros](#tratamento-de-erros)

---

## 🎯 Visão Geral

Este endpoint retorna **estatísticas agregadas** sobre os colaboradores (usuários) do sistema, sem expor dados pessoais. É ideal para dashboards gerenciais com cards de KPIs, gráficos e análises de RH.

**Características:**
- ✅ Apenas dados agregados (sem nomes, emails, CPF, etc.)
- ✅ 4 queries SQL otimizadas
- ✅ Filtros flexíveis (estado, cidade, período, status)
- ✅ Tratamento de valores nulos (retorna `null` quando não há dados)
- ✅ Percentuais sempre somam 100%

---

## 🔐 Endpoint e Autenticação

### URL Base
```
GET https://api.maiscapinhas.com.br/api/v1/users/kpis
```

### Headers Obrigatórios
```http
Authorization: Bearer {seu_token_sanctum}
Content-Type: application/json
Accept: application/json
```

### Exemplo com Axios
```typescript
import axios from 'axios';

const response = await axios.get('/api/v1/users/kpis', {
  headers: {
    'Authorization': `Bearer ${token}`,
  },
  params: {
    active: 1,
    state: 'SC',
  }
});
```

---

## 🔍 Parâmetros de Filtro

Todos os parâmetros são **opcionais** e enviados via **query string**.

| Parâmetro | Tipo | Default | Valores Aceitos | Descrição |
|-----------|------|---------|-----------------|-----------|
| `active` | string | `"1"` | `0`, `1`, `all` | Filtrar por status ativo/inativo |
| `state` | string | `null` | Sigla UF (2 chars) | Filtrar por estado (ex: `SC`, `SP`) |
| `city` | string | `null` | Nome da cidade | Filtrar por cidade (ex: `Itapema`) |
| `date_from` | date | `null` | `YYYY-MM-DD` | Data inicial de cadastro |
| `date_to` | date | `null` | `YYYY-MM-DD` | Data final de cadastro |

### Validações

**`active`**
- ❌ Erro 422 se não for `0`, `1` ou `all`
- Mensagem: `"O parâmetro active deve ser 0, 1 ou all."`

**`state`**
- ❌ Erro 422 se não tiver exatamente 2 caracteres
- Mensagem: `"O estado deve ter exatamente 2 caracteres (ex: SC, SP)."`

**`date_to`**
- ❌ Erro 422 se for anterior a `date_from`
- Mensagem: `"A data final deve ser igual ou posterior à data inicial."`

---

## 📊 Schema JSON Completo

```typescript
interface UserKpisResponse {
  filters: {
    active: number | 'all';      // 0, 1 ou "all"
    state: string | null;         // "SC", "SP", etc.
    city: string | null;          // "Itapema", etc.
    date_from: string | null;     // "2025-01-01"
    date_to: string | null;       // "2025-12-31"
  };
  
  totals: {
    users_total: number;          // Total de usuários (respeitando filtros)
    active_total: number;         // Total de ativos
    inactive_total: number;       // Total de inativos
    with_birth_date_total: number;    // Quantos têm data de nascimento
    with_hire_date_total: number;     // Quantos têm data de contratação
    without_city_total: number;       // Quantos não têm cidade cadastrada
  };
  
  age: {
    avg_age_years: number | null;         // Idade média (2 casas decimais)
    youngest_age_years: number | null;    // Idade do mais novo (anos completos)
    youngest_birth_date: string | null;   // Data de nascimento do mais novo
    oldest_age_years: number | null;      // Idade do mais velho (anos completos)
    oldest_birth_date: string | null;     // Data de nascimento do mais velho
    age_population_total: number;         // Quantos entraram no cálculo
  };
  
  tenure: {
    avg_tenure_days: number | null;       // Tempo médio em dias
    avg_tenure_months: number | null;     // Tempo médio em meses (1 casa decimal)
    longest_tenure_days: number | null;   // Maior tempo de casa (dias)
    longest_hire_date: string | null;     // Data de contratação mais antiga
    newest_tenure_days: number | null;    // Menor tempo de casa (dias)
    newest_hire_date: string | null;      // Data de contratação mais recente
    tenure_population_total: number;      // Quantos entraram no cálculo
  };
  
  distribution: {
    cities_total_distinct: number;        // Quantas cidades diferentes
    top_city: {                           // Cidade com mais colaboradores
      city: string;
      qty: number;
      pct: number;                        // Percentual (2 casas decimais)
    } | null;
    by_city: Array<{                      // Lista ordenada por qty DESC
      city: string;                       // Nome da cidade ou "(Sem cidade)"
      qty: number;                        // Quantidade de colaboradores
      pct: number;                        // Percentual (2 casas decimais)
    }>;
  };
}
```

### Exemplo de Resposta Real

```json
{
  "filters": {
    "active": 1,
    "state": "SC",
    "city": null,
    "date_from": null,
    "date_to": null
  },
  "totals": {
    "users_total": 35,
    "active_total": 35,
    "inactive_total": 0,
    "with_birth_date_total": 28,
    "with_hire_date_total": 32,
    "without_city_total": 2
  },
  "age": {
    "avg_age_years": 29.45,
    "youngest_age_years": 19,
    "youngest_birth_date": "2005-03-15",
    "oldest_age_years": 52,
    "oldest_birth_date": "1972-08-22",
    "age_population_total": 28
  },
  "tenure": {
    "avg_tenure_days": 420,
    "avg_tenure_months": 13.8,
    "longest_tenure_days": 1825,
    "longest_hire_date": "2021-01-15",
    "newest_tenure_days": 30,
    "newest_hire_date": "2025-12-10",
    "tenure_population_total": 32
  },
  "distribution": {
    "cities_total_distinct": 3,
    "top_city": {
      "city": "Itapema",
      "qty": 15,
      "pct": 42.86
    },
    "by_city": [
      { "city": "Itapema", "qty": 15, "pct": 42.86 },
      { "city": "Tijucas", "qty": 12, "pct": 34.29 },
      { "city": "Florianópolis", "qty": 8, "pct": 22.86 }
    ]
  }
}
```

---

## 📖 Detalhamento dos Campos

### 🔹 `filters`

Espelho dos filtros aplicados na requisição. Use para exibir ao usuário quais filtros estão ativos.

**Exemplo de uso:**
```tsx
{data.filters.state && (
  <Badge>Estado: {data.filters.state}</Badge>
)}
```

---

### 🔹 `totals`

Contadores gerais de colaboradores.

| Campo | Descrição | Uso Sugerido |
|-------|-----------|--------------|
| `users_total` | Total de usuários que atendem aos filtros | Card principal "Total de Colaboradores" |
| `active_total` | Quantos estão ativos | Card "Ativos" com badge verde |
| `inactive_total` | Quantos estão inativos | Card "Inativos" com badge vermelho |
| `with_birth_date_total` | Quantos têm data de nascimento cadastrada | Indicador de completude de cadastro |
| `with_hire_date_total` | Quantos têm data de contratação cadastrada | Indicador de completude de cadastro |
| `without_city_total` | Quantos não têm cidade cadastrada | Alerta de dados incompletos |

**Exemplo de Card:**
```tsx
<Card>
  <CardHeader>
    <Users className="w-4 h-4" />
    <CardTitle>Total de Colaboradores</CardTitle>
  </CardHeader>
  <CardContent>
    <div className="text-3xl font-bold">{data.totals.users_total}</div>
    <div className="text-sm text-muted-foreground">
      {data.totals.active_total} ativos • {data.totals.inactive_total} inativos
    </div>
  </CardContent>
</Card>
```

---

### 🔹 `age`

Estatísticas de idade dos colaboradores.

> ⚠️ **Importante**: Se `age_population_total === 0`, todos os campos serão `null`.

| Campo | Descrição | Formato |
|-------|-----------|---------|
| `avg_age_years` | Idade média | `number` (2 casas decimais) |
| `youngest_age_years` | Idade do mais novo | `number` (anos completos) |
| `youngest_birth_date` | Data de nascimento do mais novo | `string` (YYYY-MM-DD) |
| `oldest_age_years` | Idade do mais velho | `number` (anos completos) |
| `oldest_birth_date` | Data de nascimento do mais velho | `string` (YYYY-MM-DD) |
| `age_population_total` | Quantos têm data de nascimento | `number` |

**Exemplo de Card:**
```tsx
<Card>
  <CardHeader>
    <Cake className="w-4 h-4" />
    <CardTitle>Idade Média</CardTitle>
  </CardHeader>
  <CardContent>
    {data.age.avg_age_years !== null ? (
      <>
        <div className="text-3xl font-bold">{data.age.avg_age_years} anos</div>
        <div className="text-sm text-muted-foreground">
          Mais novo: {data.age.youngest_age_years} anos • 
          Mais velho: {data.age.oldest_age_years} anos
        </div>
        <Progress value={(data.age.age_population_total / data.totals.users_total) * 100} />
        <p className="text-xs text-muted-foreground mt-1">
          {data.age.age_population_total} de {data.totals.users_total} com data de nascimento
        </p>
      </>
    ) : (
      <p className="text-sm text-muted-foreground">Nenhum colaborador com data de nascimento cadastrada</p>
    )}
  </CardContent>
</Card>
```

---

### 🔹 `tenure`

Estatísticas de tempo de empresa.

> ⚠️ **Importante**: Se `tenure_population_total === 0`, todos os campos serão `null`.

| Campo | Descrição | Formato |
|-------|-----------|---------|
| `avg_tenure_days` | Tempo médio em dias | `number` (inteiro) |
| `avg_tenure_months` | Tempo médio em meses | `number` (1 casa decimal) |
| `longest_tenure_days` | Maior tempo de casa (dias) | `number` |
| `longest_hire_date` | Data de contratação mais antiga | `string` (YYYY-MM-DD) |
| `newest_tenure_days` | Menor tempo de casa (dias) | `number` |
| `newest_hire_date` | Data de contratação mais recente | `string` (YYYY-MM-DD) |
| `tenure_population_total` | Quantos têm data de contratação | `number` |

**Conversões úteis:**
```typescript
// Converter dias para anos
const avgYears = (data.tenure.avg_tenure_days / 365.25).toFixed(1);

// Converter dias para meses e dias
const months = Math.floor(data.tenure.longest_tenure_days / 30.44);
const days = data.tenure.longest_tenure_days % 30;

// Formatar data
const formattedDate = new Date(data.tenure.longest_hire_date).toLocaleDateString('pt-BR');
```

**Exemplo de Card:**
```tsx
<Card>
  <CardHeader>
    <Briefcase className="w-4 h-4" />
    <CardTitle>Tempo Médio de Empresa</CardTitle>
  </CardHeader>
  <CardContent>
    {data.tenure.avg_tenure_months !== null ? (
      <>
        <div className="text-3xl font-bold">{data.tenure.avg_tenure_months} meses</div>
        <div className="text-sm text-muted-foreground">
          {(data.tenure.avg_tenure_days / 365.25).toFixed(1)} anos
        </div>
        <Separator className="my-2" />
        <div className="grid grid-cols-2 gap-2 text-sm">
          <div>
            <p className="text-muted-foreground">Mais antigo</p>
            <p className="font-semibold">{Math.floor(data.tenure.longest_tenure_days / 365.25)} anos</p>
          </div>
          <div>
            <p className="text-muted-foreground">Mais recente</p>
            <p className="font-semibold">{data.tenure.newest_tenure_days} dias</p>
          </div>
        </div>
      </>
    ) : (
      <p className="text-sm text-muted-foreground">Nenhum colaborador com data de contratação cadastrada</p>
    )}
  </CardContent>
</Card>
```

---

### 🔹 `distribution`

Distribuição geográfica dos colaboradores por cidade.

| Campo | Descrição |
|-------|-----------|
| `cities_total_distinct` | Quantas cidades diferentes |
| `top_city` | Cidade com mais colaboradores (objeto ou `null`) |
| `by_city` | Array ordenado por quantidade (DESC) |

**Observações:**
- Cidades sem nome ou vazias aparecem como `"(Sem cidade)"`
- Percentuais sempre somam 100% (com precisão de 2 casas decimais)
- Array `by_city` está ordenado por `qty` descendente

**Exemplo de Gráfico de Pizza:**
```tsx
import { PieChart, Pie, Cell, ResponsiveContainer, Legend, Tooltip } from 'recharts';

const COLORS = ['#0088FE', '#00C49F', '#FFBB28', '#FF8042', '#8884D8'];

<ResponsiveContainer width="100%" height={300}>
  <PieChart>
    <Pie
      data={data.distribution.by_city}
      dataKey="qty"
      nameKey="city"
      cx="50%"
      cy="50%"
      outerRadius={80}
      label={({ city, pct }) => `${city} (${pct}%)`}
    >
      {data.distribution.by_city.map((entry, index) => (
        <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
      ))}
    </Pie>
    <Tooltip formatter={(value, name, props) => [`${value} colaboradores`, props.payload.city]} />
    <Legend />
  </PieChart>
</ResponsiveContainer>
```

**Exemplo de Lista com Barras:**
```tsx
<div className="space-y-2">
  {data.distribution.by_city.map((city) => (
    <div key={city.city} className="flex items-center gap-2">
      <MapPin className="w-4 h-4 text-muted-foreground" />
      <div className="flex-1">
        <div className="flex justify-between text-sm mb-1">
          <span className="font-medium">{city.city}</span>
          <span className="text-muted-foreground">{city.qty} ({city.pct}%)</span>
        </div>
        <Progress value={city.pct} className="h-2" />
      </div>
    </div>
  ))}
</div>
```

---

## 🔧 Exemplos de Requisições

### 1️⃣ Todos os Colaboradores Ativos (Default)
```typescript
const response = await fetch('/api/v1/users/kpis', {
  headers: { 'Authorization': `Bearer ${token}` }
});
```

### 2️⃣ Filtrar por Estado
```typescript
const response = await fetch('/api/v1/users/kpis?state=SC', {
  headers: { 'Authorization': `Bearer ${token}` }
});
```

### 3️⃣ Filtrar por Cidade
```typescript
const response = await fetch('/api/v1/users/kpis?city=Itapema', {
  headers: { 'Authorization': `Bearer ${token}` }
});
```

### 4️⃣ Incluir Inativos
```typescript
const response = await fetch('/api/v1/users/kpis?active=all', {
  headers: { 'Authorization': `Bearer ${token}` }
});
```

### 5️⃣ Análise de Crescimento (Período)
```typescript
const response = await fetch('/api/v1/users/kpis?date_from=2025-01-01&date_to=2025-12-31', {
  headers: { 'Authorization': `Bearer ${token}` }
});
```

### 6️⃣ Filtros Combinados
```typescript
const params = new URLSearchParams({
  state: 'SC',
  city: 'Itapema',
  active: '1',
  date_from: '2025-01-01',
  date_to: '2025-12-31'
});

const response = await fetch(`/api/v1/users/kpis?${params}`, {
  headers: { 'Authorization': `Bearer ${token}` }
});
```

---

## 🎨 Sugestões de UI/UX

### 📦 Cards de KPIs

**Layout Sugerido (Grid 4 colunas):**

```
┌─────────────┬─────────────┬─────────────┬─────────────┐
│   Total     │   Ativos    │  Inativos   │ Sem Cidade  │
│     45      │     42      │      3      │      2      │
└─────────────┴─────────────┴─────────────┴─────────────┘
┌─────────────┬─────────────┬─────────────┬─────────────┐
│ Idade Média │ Mais Novo   │ Mais Velho  │ Tempo Médio │
│  29.5 anos  │   19 anos   │   52 anos   │  13.8 meses │
└─────────────┴─────────────┴─────────────┴─────────────┘
```

### 📊 Gráficos Recomendados

#### 1. **Gráfico de Pizza** - Distribuição por Cidade
- **Biblioteca**: Recharts, Chart.js, ApexCharts
- **Dados**: `distribution.by_city`
- **Tipo**: Pie Chart
- **Cores**: Paleta de 5-6 cores distintas
- **Labels**: Mostrar cidade + percentual

#### 2. **Gráfico de Barras Horizontais** - Top 5 Cidades
- **Biblioteca**: Recharts
- **Dados**: `distribution.by_city.slice(0, 5)`
- **Tipo**: Bar Chart (horizontal)
- **Eixo X**: Quantidade de colaboradores
- **Eixo Y**: Nome da cidade

#### 3. **Gauge Chart** - Completude de Cadastro
- **Biblioteca**: Recharts, react-gauge-chart
- **Cálculo**: `(with_birth_date_total / users_total) * 100`
- **Tipo**: Radial/Gauge
- **Cores**: Verde (>80%), Amarelo (50-80%), Vermelho (<50%)

#### 4. **Timeline** - Distribuição de Contratações
- **Biblioteca**: Recharts (Area Chart)
- **Dados**: Agrupar por mês/ano (requer endpoint adicional ou processamento frontend)
- **Tipo**: Area Chart
- **Eixo X**: Mês/Ano
- **Eixo Y**: Quantidade de contratações

#### 5. **Cards com Ícones** - Estatísticas Rápidas
- **Biblioteca**: Lucide React (ícones)
- **Componentes**: shadcn/ui Cards
- **Ícones sugeridos**:
  - `Users` - Total
  - `UserCheck` - Ativos
  - `UserX` - Inativos
  - `Cake` - Idade média
  - `Briefcase` - Tempo de empresa
  - `MapPin` - Distribuição geográfica

### 🎯 Filtros Interativos

**Componentes Sugeridos:**

```tsx
<div className="flex gap-4 mb-6">
  {/* Filtro de Status */}
  <Select value={filters.active} onValueChange={(v) => setFilters({...filters, active: v})}>
    <SelectTrigger className="w-[180px]">
      <SelectValue placeholder="Status" />
    </SelectTrigger>
    <SelectContent>
      <SelectItem value="1">Apenas Ativos</SelectItem>
      <SelectItem value="0">Apenas Inativos</SelectItem>
      <SelectItem value="all">Todos</SelectItem>
    </SelectContent>
  </Select>

  {/* Filtro de Estado */}
  <Select value={filters.state} onValueChange={(v) => setFilters({...filters, state: v})}>
    <SelectTrigger className="w-[180px]">
      <SelectValue placeholder="Estado" />
    </SelectTrigger>
    <SelectContent>
      <SelectItem value="SC">Santa Catarina</SelectItem>
      <SelectItem value="SP">São Paulo</SelectItem>
      <SelectItem value="PR">Paraná</SelectItem>
    </SelectContent>
  </Select>

  {/* Filtro de Período */}
  <DateRangePicker
    from={filters.date_from}
    to={filters.date_to}
    onSelect={(range) => setFilters({
      ...filters,
      date_from: range.from,
      date_to: range.to
    })}
  />
</div>
```

### 📱 Responsividade

**Mobile (< 768px):**
- Cards em coluna única
- Gráficos com altura reduzida (200px)
- Tabs para separar seções (Totais, Idade, Tempo de Empresa, Distribuição)

**Tablet (768px - 1024px):**
- Grid 2 colunas para cards
- Gráficos lado a lado (50% cada)

**Desktop (> 1024px):**
- Grid 4 colunas para cards
- Gráficos em grid 2x2 ou 3x1

---

## ⚠️ Tratamento de Erros

### 401 - Não Autenticado
```typescript
if (response.status === 401) {
  // Redirecionar para login
  router.push('/login');
}
```

### 422 - Validação Falhou
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "active": ["O parâmetro active deve ser 0, 1 ou all."],
    "state": ["O estado deve ter exatamente 2 caracteres (ex: SC, SP)."],
    "date_to": ["A data final deve ser igual ou posterior à data inicial."]
  }
}
```

**Tratamento:**
```typescript
if (response.status === 422) {
  const { errors } = await response.json();
  
  // Exibir toast com erros
  Object.entries(errors).forEach(([field, messages]) => {
    toast.error(messages[0]);
  });
}
```

### 500 - Erro do Servidor
```typescript
if (response.status === 500) {
  toast.error('Erro ao carregar estatísticas. Tente novamente.');
}
```

---

## 🔄 Hook React Customizado

```typescript
import { useState, useEffect } from 'react';
import { useAuth } from '@/hooks/useAuth';

interface KpiFilters {
  active?: '0' | '1' | 'all';
  state?: string;
  city?: string;
  date_from?: string;
  date_to?: string;
}

export function useUserKpis(filters: KpiFilters = {}) {
  const [data, setData] = useState<UserKpisResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);
  const { token } = useAuth();

  useEffect(() => {
    const fetchKpis = async () => {
      setLoading(true);
      setError(null);

      try {
        const params = new URLSearchParams(
          Object.entries(filters).filter(([_, v]) => v != null) as [string, string][]
        );

        const response = await fetch(`/api/v1/users/kpis?${params}`, {
          headers: {
            'Authorization': `Bearer ${token}`,
            'Accept': 'application/json',
          },
        });

        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`);
        }

        const json = await response.json();
        setData(json);
      } catch (err) {
        setError(err as Error);
      } finally {
        setLoading(false);
      }
    };

    fetchKpis();
  }, [JSON.stringify(filters), token]);

  return { data, loading, error, refetch: () => fetchKpis() };
}
```

**Uso:**
```tsx
function DashboardKpis() {
  const { data, loading, error } = useUserKpis({ active: '1', state: 'SC' });

  if (loading) return <Skeleton />;
  if (error) return <ErrorAlert message={error.message} />;
  if (!data) return null;

  return (
    <div className="grid grid-cols-4 gap-4">
      <KpiCard title="Total" value={data.totals.users_total} icon={Users} />
      <KpiCard title="Ativos" value={data.totals.active_total} icon={UserCheck} />
      {/* ... */}
    </div>
  );
}
```

---

## 📌 Checklist de Integração

- [ ] Configurar autenticação Bearer Token
- [ ] Criar interface TypeScript `UserKpisResponse`
- [ ] Implementar hook `useUserKpis` com filtros
- [ ] Criar componentes de Cards para cada KPI
- [ ] Implementar gráfico de pizza (distribuição por cidade)
- [ ] Implementar filtros interativos (status, estado, cidade, período)
- [ ] Adicionar tratamento de erros (401, 422, 500)
- [ ] Implementar loading states (Skeleton)
- [ ] Adicionar responsividade mobile
- [ ] Testar com dados vazios (`null` values)
- [ ] Adicionar tooltips explicativos nos cards
- [ ] Implementar export para PDF/Excel (opcional)

---

## 🚀 Próximos Passos

1. **Implementar filtros salvos**: Permitir que o usuário salve combinações de filtros favoritas
2. **Comparação de períodos**: Adicionar MoM (Month over Month) e YoY (Year over Year)
3. **Alertas automáticos**: Notificar quando KPIs críticos mudarem (ex: queda de 10% em ativos)
4. **Export de dados**: Botão para baixar relatório em PDF ou Excel
5. **Drill-down**: Ao clicar em uma cidade, mostrar lista de colaboradores daquela cidade

---

**Dúvidas ou sugestões?** Entre em contato com o time de backend! 🚀
