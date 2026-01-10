# ⚠️ Problema de Upload de Avatar - RESOLVIDO NO BACKEND

## Status: Backend Funcionando ✅

O backend foi testado e está **funcionando corretamente**. O problema está na forma como o frontend está enviando a requisição.

---

---

## O Que o Frontend Está Fazendo de Errado

### Problema 1: O arquivo não está chegando no servidor

O erro `"The avatar field is required"` significa que o servidor **não recebe nenhum arquivo** na requisição.

**Causas mais comuns:**

1. **Content-Type definido manualmente** - NUNCA faça isso com FormData!
2. **Nome do campo incorreto** - deve ser exatamente `avatar`
3. **File input vazio** - verificar se o arquivo foi realmente selecionado
4. **Middleware removendo o arquivo** - possível problema de interceptors

---

## Como Fazer Corretamente

### ✅ Código Correto (React/TypeScript)

```typescript
// Em um componente React
const handleAvatarUpload = async (event: React.ChangeEvent<HTMLInputElement>) => {
  const file = event.target.files?.[0];
  if (!file) {
    console.error('Nenhum arquivo selecionado');
    return;
  }

  console.log('Arquivo selecionado:', {
    name: file.name,
    type: file.type,
    size: file.size
  });

  const formData = new FormData();
  formData.append('avatar', file);  // ← Campo DEVE ser "avatar"

  // Debug: verificar o conteúdo do FormData
  for (const [key, value] of formData.entries()) {
    console.log('FormData entry:', key, value);
  }

  try {
    const response = await fetch(`/api/v1/users/${userId}/avatar`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json',
        // ❌❌❌ NÃO ADICIONE Content-Type ❌❌❌
      },
      body: formData
    });

    const data = await response.json();
    console.log('Response:', response.status, data);

    if (!response.ok) {
      throw new Error(data.message || 'Erro no upload');
    }

    return data;
  } catch (error) {
    console.error('Upload failed:', error);
    throw error;
  }
};
```

### ❌ Código Incorreto (NÃO FAÇA ISSO)

```typescript
// ❌ ERRADO - Content-Type manual
const response = await fetch(url, {
  headers: {
    'Content-Type': 'multipart/form-data',  // ❌ NUNCA FAÇA ISSO!
  },
  body: formData
});

// ❌ ERRADO - Nome do campo incorreto
formData.append('file', file);        // Errado
formData.append('image', file);       // Errado
formData.append('photo', file);       // Errado para avatar (correto para loja)
formData.append('avatar', file);      // ✅ Correto para avatar de usuário
```

---

## Verificar com Axios

Se estiver usando Axios, certifique-se de:

```typescript
// ✅ Correto com Axios
const formData = new FormData();
formData.append('avatar', file);

const response = await axios.post(`/api/v1/users/${userId}/avatar`, formData, {
  headers: {
    'Authorization': `Bearer ${token}`,
    // NÃO defina Content-Type!
  },
});
```

### Problema Comum com Axios: Interceptors

Se você tem um interceptor que adiciona `Content-Type: application/json`, ele pode estar sobrescrevendo o Content-Type correto:

```typescript
// ❌ Interceptor problemático
axios.interceptors.request.use((config) => {
  config.headers['Content-Type'] = 'application/json'; // ❌ Problema!
  return config;
});

// ✅ Interceptor correto
axios.interceptors.request.use((config) => {
  // Só adiciona Content-Type se não for FormData
  if (!(config.data instanceof FormData)) {
    config.headers['Content-Type'] = 'application/json';
  }
  return config;
});
```

---

## Checklist de Debug

Execute no console do browser:

```javascript
// 1. Verificar se o arquivo foi selecionado
const fileInput = document.querySelector('input[type="file"]');
console.log('Files:', fileInput.files);

// 2. Verificar FormData
const formData = new FormData();
formData.append('avatar', fileInput.files[0]);
for (const [key, value] of formData.entries()) {
  console.log(key, value);
}
// Deve mostrar: "avatar" File {...}

// 3. Fazer requisição de teste
fetch('/api/v1/users/1/avatar', {
  method: 'POST',
  headers: { 'Authorization': 'Bearer SEU_TOKEN' },
  body: formData
})
.then(r => r.json())
.then(console.log)
.catch(console.error);
```

---

## Endpoints e Nomes de Campos

| Endpoint | Campo FormData | Método |
|----------|----------------|--------|
| `/api/v1/users/{id}/avatar` | `avatar` | POST |
| `/api/v1/stores/{id}/photo` | `photo` | POST |
| `/api/v1/me` | JSON body | PUT |

---

## Se Ainda Não Funcionar

1. Abra o DevTools (F12) → Network
2. Faça o upload
3. Clique na requisição que falhou
4. Verifique:
   - **Request Headers**: Content-Type deve ser `multipart/form-data; boundary=...`
   - **Request Payload**: Deve mostrar a parte do arquivo

Se o Content-Type for `application/json` ou não tiver `boundary=`, o problema está no código do frontend.
