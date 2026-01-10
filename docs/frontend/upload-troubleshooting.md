# Troubleshooting: Upload de Fotos

Guia para resolver os erros de upload de avatar e foto de loja.

---

## Correções Aplicadas no Backend

> [!IMPORTANT]
> As seguintes correções foram feitas. Faça deploy do backend antes de testar.

### 1. Super Admin agora tem acesso total
- ✅ `PUT /api/v1/users/{id}/avatar` - Super Admin pode atualizar avatar de qualquer usuário
- ✅ `PUT /api/v1/stores/{id}/photo` - Super Admin pode atualizar foto de qualquer loja

### 2. Rotas POST adicionadas (method spoofing)
- ✅ `POST /api/v1/users/{id}/avatar` - Alternativa ao PUT
- ✅ `POST /api/v1/stores/{id}/photo` - Alternativa ao PUT

---

## Problema 1: PUT /api/v1/me retornando 405

### Causa
O erro **"The avatar field is required"** com status **405** indica que a requisição está indo para a rota errada (provavelmente `/api/v1/users/...` em vez de `/api/v1/me`).

### Solução
Verifique se está usando a URL correta:

```javascript
// ✅ CORRETO - Atualizar email/whatsapp
const response = await fetch('/api/v1/me', {
  method: 'PUT',
  headers: {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json'  // Obrigatório para JSON
  },
  body: JSON.stringify({
    email: 'novoemail@example.com',
    whatsapp: '47988887777'
  })
});
```

> [!WARNING]
> Não confunda `/me` com `/users/{id}`. São endpoints diferentes!

---

## Problema 2: Avatar upload retornando 422

### Causa
O Laravel não recebe o arquivo corretamente. Isso geralmente ocorre por:
1. Nome do campo incorreto
2. Content-Type definido manualmente
3. PUT + multipart não funciona em alguns servidores

### Solução: Use POST com method spoofing

```javascript
// ✅ CORRETO - Upload de avatar
const formData = new FormData();
formData.append('avatar', arquivoSelecionado);  // Campo DEVE ser "avatar"

const response = await fetch(`/api/v1/users/${userId}/avatar`, {
  method: 'POST',  // Use POST, não PUT
  headers: {
    'Authorization': `Bearer ${token}`
    // ❌ NÃO defina Content-Type! O browser define automaticamente
  },
  body: formData
});
```

> [!CAUTION]
> **NUNCA** defina `Content-Type: multipart/form-data` manualmente! O browser precisa definir o boundary automaticamente.

### Alternativa: PUT direto (se o servidor suportar)

```javascript
const formData = new FormData();
formData.append('avatar', arquivoSelecionado);

const response = await fetch(`/api/v1/users/${userId}/avatar`, {
  method: 'PUT',
  headers: {
    'Authorization': `Bearer ${token}`
  },
  body: formData
});
```

---

## Problema 3: Store photo upload

### Solução

```javascript
// ✅ CORRETO - Upload de foto da loja
const formData = new FormData();
formData.append('photo', arquivoSelecionado);  // Campo DEVE ser "photo"

const response = await fetch(`/api/v1/stores/${storeId}/photo`, {
  method: 'POST',  // Use POST
  headers: {
    'Authorization': `Bearer ${token}`
    // ❌ NÃO defina Content-Type!
  },
  body: formData
});
```

---

## Resumo: Nomes dos Campos

| Endpoint | Nome do Campo | Método Recomendado |
|----------|---------------|-------------------|
| `PUT /api/v1/me` | `email`, `whatsapp` (JSON) | PUT |
| `/api/v1/users/{id}/avatar` | `avatar` | POST |
| `/api/v1/stores/{id}/photo` | `photo` | POST |

---

## Código de Referência Completo

### Hook de Upload

```typescript
// hooks/useUpload.ts
export function useUpload() {
  const uploadAvatar = async (userId: number, file: File) => {
    const formData = new FormData();
    formData.append('avatar', file);
    
    const response = await fetch(`/api/v1/users/${userId}/avatar`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${getToken()}`
      },
      body: formData
    });
    
    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || error.error?.message);
    }
    
    return response.json();
  };

  const uploadStorePhoto = async (storeId: number, file: File) => {
    const formData = new FormData();
    formData.append('photo', file);
    
    const response = await fetch(`/api/v1/stores/${storeId}/photo`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${getToken()}`
      },
      body: formData
    });
    
    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || error.error?.message);
    }
    
    return response.json();
  };

  const updateProfile = async (data: { email?: string; whatsapp?: string }) => {
    const response = await fetch('/api/v1/me', {
      method: 'PUT',
      headers: {
        'Authorization': `Bearer ${getToken()}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(data)
    });
    
    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message);
    }
    
    return response.json();
  };

  return { uploadAvatar, uploadStorePhoto, updateProfile };
}
```

---

## Checklist de Verificação

- [ ] Usar `POST` para uploads de arquivo
- [ ] **Não** definir `Content-Type` manualmente para FormData
- [ ] Usar nome de campo correto: `avatar` ou `photo`
- [ ] Usar `PUT` com `Content-Type: application/json` para `/me`
- [ ] Verificar se o token está correto
- [ ] Verificar dimensões mínimas: avatar (200x200), store photo (800x600)

---

## Permissões por Role

| Ação | Vendedor | Gerente | Admin | Super Admin |
|------|----------|---------|-------|-------------|
| Atualizar próprio avatar | ✅ | ✅ | ✅ | ✅ |
| Atualizar avatar de outros | ❌ | ❌ | ✅ | ✅ |
| Atualizar foto da própria loja | ❌ | ✅ | ✅ | ✅ |
| Atualizar foto de qualquer loja | ❌ | ❌ | ✅ | ✅ |
