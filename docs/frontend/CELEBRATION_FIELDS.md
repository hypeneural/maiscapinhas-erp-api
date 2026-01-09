# 📋 Resposta Backend - Campos para Celebração

> **Data**: 09/01/2026  
> **Status**: ✅ **IMPLEMENTADO**  
> **Endpoint Atualizado**: `GET /api/v1/me`

---

## ✅ Respostas às Perguntas

### 1. O campo `birth_date` já está sendo retornado pelo `/me`?

**Resposta**: Agora **SIM**! ✅

O campo já existia no banco de dados mas **não estava sendo retornado** pelo endpoint `/me`. Foi adicionado nesta atualização.

| Propriedade | Valor |
|-------------|-------|
| **Formato** | `YYYY-MM-DD` (ex: `"1990-05-15"`) |
| **Tipo** | `string \| null` |
| **Nullable** | Sim, pode ser `null` |

---

### 2. Existe um campo de data de admissão (`hire_date`)?

**Resposta**: **SIM**! ✅

O campo `hire_date` já existia na tabela `users` e agora está sendo retornado pelo endpoint `/me`.

| Propriedade | Valor |
|-------------|-------|
| **Formato** | `YYYY-MM-DD` (ex: `"2022-01-09"`) |
| **Tipo** | `string \| null` |
| **Nullable** | Sim, pode ser `null` |

---

### 3. Os campos podem ser `null`?

**Resposta**: **SIM**! ✅

Ambos os campos são **nullable** no banco de dados. Usuários que não têm a data preenchida receberão `null`.

---

## 📦 Nova Estrutura do `/me`

```json
{
  "data": {
    "user": {
      "id": 1,
      "name": "João Silva",
      "email": "joao@exemplo.com",
      "active": true,
      "whatsapp": "47999999999",
      "avatar_url": "https://api.maiscapinhas.com.br/storage/avatars/1.jpg",
      "instagram": "@joaosilva",
      "birth_date": "1990-05-15",    // ✅ ADICIONADO
      "hire_date": "2022-01-09",     // ✅ ADICIONADO
      "created_at": "2022-01-09T10:00:00+00:00"
    },
    "stores": [
      {
        "id": 1,
        "name": "Mais Capinhas Tijucas",
        "city": "Tijucas",
        "role": "admin"
      }
    ]
  },
  "meta": {
    "timestamp": "2026-01-09T10:20:00-03:00"
  }
}
```

---

## 🎯 TypeScript Interface Atualizado

```typescript
interface User {
  id: number;
  name: string;
  email: string;
  active: boolean;
  whatsapp: string | null;
  avatar_url: string | null;
  instagram: string | null;
  birth_date: string | null;  // YYYY-MM-DD
  hire_date: string | null;   // YYYY-MM-DD
  created_at: string;         // ISO 8601
}

interface MeResponse {
  data: {
    user: User;
    stores: Array<{
      id: number;
      name: string;
      city: string;
      role: 'admin' | 'gerente' | 'conferente' | 'vendedor';
    }>;
  };
  meta: {
    timestamp: string;
  };
}
```

---

## 🧪 Exemplo de Implementação Frontend

```typescript
// utils/celebration.ts

export function checkCelebrations(user: User) {
  if (!user.birth_date && !user.hire_date) {
    return { isBirthday: false, isWorkAnniversary: false, yearsAtCompany: 0 };
  }

  const today = new Date();
  
  // Verifica aniversário
  let isBirthday = false;
  if (user.birth_date) {
    const birthDate = new Date(user.birth_date + 'T00:00:00');
    isBirthday = today.getMonth() === birthDate.getMonth() 
              && today.getDate() === birthDate.getDate();
  }

  // Verifica aniversário de empresa
  let isWorkAnniversary = false;
  let yearsAtCompany = 0;
  if (user.hire_date) {
    const hireDate = new Date(user.hire_date + 'T00:00:00');
    yearsAtCompany = today.getFullYear() - hireDate.getFullYear();
    isWorkAnniversary = today.getMonth() === hireDate.getMonth() 
                     && today.getDate() === hireDate.getDate()
                     && yearsAtCompany > 0;
  }

  return { isBirthday, isWorkAnniversary, yearsAtCompany };
}

// Controle de exibição única por dia
export function shouldShowCelebration(type: 'birthday' | 'anniversary'): boolean {
  const today = new Date().toISOString().split('T')[0];
  const key = `celebration-${type}-shown-${today}`;
  return !localStorage.getItem(key);
}

export function markCelebrationShown(type: 'birthday' | 'anniversary'): void {
  const today = new Date().toISOString().split('T')[0];
  const key = `celebration-${type}-shown-${today}`;
  localStorage.setItem(key, 'true');
}
```

---

## ⚠️ Observações Importantes

1. **Null Safety**: Sempre verifique se `birth_date` e `hire_date` não são `null` antes de criar objetos `Date`.

2. **Timezone**: Os campos retornam apenas a data (`YYYY-MM-DD`), sem hora. Ao converter para `Date`, adicione `T00:00:00` para evitar problemas de fuso horário.

3. **Primeiro ano de empresa**: A lógica `yearsAtCompany > 0` garante que o modal de aniversário de empresa só apareça após completar pelo menos 1 ano.

4. **Formato consistente**: Ambos os campos sempre retornam no formato `YYYY-MM-DD` ou `null`.

---

## 🚀 Próximos Passos (Frontend)

- [ ] Atualizar interface `User` no TypeScript
- [ ] Criar componente `CelebrationModal`
- [ ] Implementar hook `useCelebration`
- [ ] Adicionar animação de confetes (sugestão: `canvas-confetti`)
- [ ] Testar com usuários que fazem aniversário hoje

---

**Arquivo alterado no backend**: `app/Http/Controllers/Api/V1/MeController.php`
