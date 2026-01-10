# API de Perfil do Usuário

Documentação para o frontend consumir os endpoints de perfil do usuário autenticado.

## Endpoints Disponíveis

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| `GET` | `/api/v1/me` | Obter perfil do usuário atual |
| `PUT` | `/api/v1/me` | Atualizar email e whatsapp |
| `PUT` | `/api/v1/users/{userId}/avatar` | Atualizar avatar (foto de perfil) |

---

## 1. Obter Perfil do Usuário

### Request

```http
GET /api/v1/me
Authorization: Bearer {token}
```

### Response Schema

```typescript
interface MeResponse {
  data: {
    user: User;
    stores: UserStore[];
  };
  meta: {
    timestamp: string;
  };
}

interface User {
  id: number;
  name: string;
  email: string;
  active: boolean;
  is_super_admin: boolean;
  whatsapp: string | null;
  avatar_url: string | null;
  instagram: string | null;
  birth_date: string | null;  // formato: "YYYY-MM-DD"
  hire_date: string | null;   // formato: "YYYY-MM-DD"
  created_at: string;         // formato ISO 8601
}

interface UserStore {
  id: number;
  name: string;
  city: string;
  role: 'admin' | 'gerente' | 'conferente' | 'vendedor';
}
```

### Exemplo de Response

```json
{
  "data": {
    "user": {
      "id": 1,
      "name": "João Silva",
      "email": "joao@maiscapinhas.com.br",
      "active": true,
      "is_super_admin": false,
      "whatsapp": "47999999999",
      "avatar_url": "https://api.maiscapinhas.com.br/storage/users/1/avatar.jpg",
      "instagram": "@joaosilva",
      "birth_date": "1990-05-15",
      "hire_date": "2022-01-09",
      "created_at": "2026-01-01T00:00:00+00:00"
    },
    "stores": [
      { "id": 1, "name": "Mais Capinhas Tijucas", "city": "Tijucas", "role": "vendedor" },
      { "id": 2, "name": "Mais Capinhas Itapema", "city": "Itapema", "role": "gerente" }
    ]
  },
  "meta": { "timestamp": "2026-01-10T12:00:00Z" }
}
```

---

## 2. Atualizar Perfil (Email e WhatsApp)

### Request

```http
PUT /api/v1/me
Authorization: Bearer {token}
Content-Type: application/json
```

### Request Body Schema

```typescript
interface UpdateProfileRequest {
  email?: string;      // opcional, deve ser único
  whatsapp?: string;   // opcional, max 20 caracteres
}
```

> **Nota:** Envie apenas os campos que deseja atualizar. Ambos são opcionais.

### Exemplo de Request

```javascript
// Usando fetch
const response = await fetch('/api/v1/me', {
  method: 'PUT',
  headers: {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    email: 'novoemail@example.com',
    whatsapp: '47988887777'
  })
});

const data = await response.json();
```

### Response (Sucesso - 200)

Retorna os dados atualizados do perfil (mesmo schema do GET /me).

```json
{
  "data": {
    "user": {
      "id": 1,
      "name": "João Silva",
      "email": "novoemail@example.com",
      "whatsapp": "47988887777",
      ...
    },
    "stores": [...]
  }
}
```

### Response (Erro de Validação - 422)

```typescript
interface ValidationError {
  message: string;
  errors: {
    email?: string[];
    whatsapp?: string[];
  };
}
```

```json
{
  "message": "The email has already been taken.",
  "errors": {
    "email": ["The email has already been taken."]
  }
}
```

---

## 3. Atualizar Avatar (Foto de Perfil)

### Request

```http
PUT /api/v1/users/{userId}/avatar
Authorization: Bearer {token}
Content-Type: multipart/form-data
```

> **Importante:** O usuário pode atualizar apenas seu próprio avatar. Admins podem atualizar de qualquer usuário.

### Request Body

| Campo | Tipo | Obrigatório | Descrição |
|-------|------|-------------|-----------|
| `avatar` | `File` | Sim* | Arquivo de imagem (jpg, jpeg, png, webp) |
| `remove` | `boolean` | Não | Se `true`, remove o avatar atual |

*Obrigatório se `remove` não for `true`.

### Validações

- **Tipos aceitos:** jpg, jpeg, png, webp
- **Tamanho máximo:** 2MB
- **Dimensões mínimas:** 200x200px

### Exemplo de Request (Upload)

```javascript
// Usando fetch com FormData
const formData = new FormData();
formData.append('avatar', fileInput.files[0]);

const response = await fetch(`/api/v1/users/${userId}/avatar`, {
  method: 'PUT',
  headers: {
    'Authorization': `Bearer ${token}`,
    // NÃO defina Content-Type manualmente! O browser define automaticamente
  },
  body: formData
});
```

### Exemplo de Request (Remover Avatar)

```javascript
const formData = new FormData();
formData.append('remove', 'true');

const response = await fetch(`/api/v1/users/${userId}/avatar`, {
  method: 'PUT',
  headers: {
    'Authorization': `Bearer ${token}`,
  },
  body: formData
});
```

### Response Schema

```typescript
interface AvatarResponse {
  data: {
    user_id: number;
    avatar_url: string | null;
  };
  meta: {
    request_id: string;
    timestamp: string;
  };
}
```

### Response (Sucesso - 200)

```json
{
  "data": {
    "user_id": 1,
    "avatar_url": "https://api.maiscapinhas.com.br/storage/users/1/avatar.jpg"
  },
  "meta": {
    "request_id": "uuid-here",
    "timestamp": "2026-01-10T12:00:00Z"
  }
}
```

### Response (Erro - 403)

```json
{
  "error": {
    "code": 403,
    "message": "Você não tem permissão para atualizar este avatar."
  }
}
```

---

## Sugestões de Implementação no Frontend

### 1. Hook para Perfil do Usuário

```typescript
// hooks/useProfile.ts
import { useState, useCallback } from 'react';
import { api } from '@/lib/api';

interface UpdateProfileData {
  email?: string;
  whatsapp?: string;
}

export function useProfile() {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const updateProfile = useCallback(async (data: UpdateProfileData) => {
    setLoading(true);
    setError(null);
    
    try {
      const response = await api.put('/me', data);
      return response.data;
    } catch (err: any) {
      const message = err.response?.data?.message || 'Erro ao atualizar perfil';
      setError(message);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  const updateAvatar = useCallback(async (userId: number, file: File | null) => {
    setLoading(true);
    setError(null);
    
    const formData = new FormData();
    if (file) {
      formData.append('avatar', file);
    } else {
      formData.append('remove', 'true');
    }
    
    try {
      const response = await api.put(`/users/${userId}/avatar`, formData);
      return response.data;
    } catch (err: any) {
      const message = err.response?.data?.error?.message || 'Erro ao atualizar avatar';
      setError(message);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  return { updateProfile, updateAvatar, loading, error };
}
```

### 2. Componente de Edição de Perfil

```tsx
// components/EditProfileModal.tsx
import { useState } from 'react';
import { useProfile } from '@/hooks/useProfile';
import { useAuth } from '@/contexts/AuthContext';

export function EditProfileModal({ onClose, onSuccess }) {
  const { user, refreshUser } = useAuth();
  const { updateProfile, updateAvatar, loading, error } = useProfile();
  
  const [email, setEmail] = useState(user?.email || '');
  const [whatsapp, setWhatsapp] = useState(user?.whatsapp || '');
  const [avatarFile, setAvatarFile] = useState<File | null>(null);
  const [avatarPreview, setAvatarPreview] = useState(user?.avatar_url);

  const handleAvatarChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      setAvatarFile(file);
      setAvatarPreview(URL.createObjectURL(file));
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    try {
      // Atualizar email/whatsapp se mudaram
      if (email !== user?.email || whatsapp !== user?.whatsapp) {
        await updateProfile({ email, whatsapp });
      }
      
      // Atualizar avatar se selecionado
      if (avatarFile && user?.id) {
        await updateAvatar(user.id, avatarFile);
      }
      
      await refreshUser();
      onSuccess?.();
      onClose();
    } catch (err) {
      // Erro já tratado no hook
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      {/* Avatar Upload */}
      <div className="avatar-upload">
        <img src={avatarPreview || '/default-avatar.png'} alt="Avatar" />
        <input 
          type="file" 
          accept="image/jpeg,image/png,image/webp"
          onChange={handleAvatarChange}
        />
      </div>

      {/* Email */}
      <div className="form-group">
        <label>Email</label>
        <input
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
        />
      </div>

      {/* WhatsApp */}
      <div className="form-group">
        <label>WhatsApp</label>
        <input
          type="tel"
          value={whatsapp}
          onChange={(e) => setWhatsapp(e.target.value)}
          placeholder="47999999999"
          maxLength={20}
        />
      </div>

      {error && <div className="error">{error}</div>}

      <button type="submit" disabled={loading}>
        {loading ? 'Salvando...' : 'Salvar'}
      </button>
    </form>
  );
}
```

### 3. Validações no Frontend

```typescript
// utils/validation.ts
export const profileValidation = {
  email: {
    required: 'Email é obrigatório',
    pattern: {
      value: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
      message: 'Email inválido'
    }
  },
  whatsapp: {
    maxLength: {
      value: 20,
      message: 'WhatsApp deve ter no máximo 20 caracteres'
    },
    pattern: {
      value: /^[0-9]+$/,
      message: 'WhatsApp deve conter apenas números'
    }
  },
  avatar: {
    maxSize: 2 * 1024 * 1024, // 2MB
    acceptedTypes: ['image/jpeg', 'image/png', 'image/webp'],
    minDimensions: { width: 200, height: 200 }
  }
};

export function validateAvatarFile(file: File): string | null {
  if (file.size > profileValidation.avatar.maxSize) {
    return 'Imagem deve ter no máximo 2MB';
  }
  if (!profileValidation.avatar.acceptedTypes.includes(file.type)) {
    return 'Formato inválido. Use JPG, PNG ou WebP';
  }
  return null;
}
```

---

## Tratamento de Erros

| Código | Descrição | Ação Sugerida |
|--------|-----------|---------------|
| 401 | Token inválido/expirado | Redirecionar para login |
| 403 | Sem permissão | Exibir mensagem de erro |
| 422 | Validação falhou | Exibir erros nos campos |
| 500 | Erro interno | Exibir mensagem genérica |

---

## Fluxo Recomendado na UI

1. **Página de Perfil**: Exibir dados atuais do usuário (GET /me)
2. **Botão "Editar Perfil"**: Abre modal de edição
3. **Modal de Edição**:
   - Campo de email (pré-preenchido)
   - Campo de whatsapp (pré-preenchido)
   - Upload de avatar com preview
   - Opção de remover avatar
4. **Ao salvar**: Chamar PUT /me e/ou PUT /users/{id}/avatar
5. **Após sucesso**: Atualizar estado do AuthContext e fechar modal
