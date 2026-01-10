# Auditoria: CRUD Admin de Usuários e Lojas

## Resumo Executivo

> [!CAUTION]
> Os CRUDs Admin atuais **NÃO contemplam todos os campos** dos models. Muitos campos estão ausentes nas operações de criação e atualização.

---

## 1. CRUD de Usuários (`/api/v1/admin/users`)

### Campos no Model User

| Campo | Tipo | Fillable | CRUD Create | CRUD Update | Validação |
|-------|------|----------|-------------|-------------|-----------|
| `name` | string | ✅ | ✅ | ✅ | required, max:255 |
| `email` | string | ✅ | ✅ | ✅ | required, unique, email |
| `password` | string | ✅ | ✅ | ✅ | required (create), optional (update), min:8 |
| `active` | boolean | ✅ | ✅ | ✅ | boolean |
| `is_super_admin` | boolean | ✅ | ✅ | ✅ | boolean |
| `birth_date` | date | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |
| `hire_date` | date | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |
| `whatsapp` | string | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |
| `avatar_url` | string | ✅ | ❌ (endpoint separado) | ❌ (endpoint separado) | N/A |
| `instagram` | string | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |
| `cpf` | string | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |
| `pix_key` | string | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |

### Campos Faltando no CRUD User

```
❌ birth_date   - Data de nascimento
❌ hire_date    - Data de contratação
❌ whatsapp     - Número WhatsApp
❌ instagram    - Instagram
❌ cpf          - CPF do funcionário
❌ pix_key      - Chave PIX para pagamentos
```

### Arquivos Afetados

- [StoreUserRequest.php](file:///c:/laragon/www/maiscapinhas-erp-api/app/Http/Requests/Admin/StoreUserRequest.php) - Criar usuário
- [UpdateUserRequest.php](file:///c:/laragon/www/maiscapinhas-erp-api/app/Http/Requests/Admin/UpdateUserRequest.php) - Atualizar usuário
- [UserController.php](file:///c:/laragon/www/maiscapinhas-erp-api/app/Http/Controllers/Api/V1/Admin/UserController.php) - store() e update()

---

## 2. CRUD de Lojas (`/api/v1/admin/stores`)

### Campos no Model Store

| Campo | Tipo | Fillable | CRUD Create | CRUD Update | Validação |
|-------|------|----------|-------------|-------------|-----------|
| `name` | string | ✅ | ✅ | ✅ | required, max:255 |
| `city` | string | ✅ | ✅ | ✅ | required, max:255 |
| `active` | boolean | ✅ | ✅ | ✅ | boolean |
| `codigo` | string | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |
| `photo_url` | string | ✅ | ❌ (endpoint separado) | ❌ (endpoint separado) | N/A |
| `address` | string | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |
| `neighborhood` | string | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |
| `state` | string | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |
| `zip_code` | string | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |
| `latitude` | decimal | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |
| `longitude` | decimal | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |
| `phone` | string | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |
| `whatsapp` | string | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |
| `instagram` | string | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |
| `opening_hours` | json | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |
| `cnpj` | string | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |
| `troco_padrao` | decimal | ✅ | ❌ **FALTA** | ❌ **FALTA** | ❌ |

### Campos Faltando no CRUD Store

```
❌ codigo        - Código da loja
❌ address       - Endereço completo
❌ neighborhood  - Bairro
❌ state         - Estado (UF)
❌ zip_code      - CEP
❌ latitude      - Latitude GPS
❌ longitude     - Longitude GPS
❌ phone         - Telefone fixo
❌ whatsapp      - WhatsApp da loja
❌ instagram     - Instagram da loja
❌ opening_hours - Horário de funcionamento (JSON)
❌ cnpj          - CNPJ da loja
❌ troco_padrao  - Valor padrão de troco
```

### Arquivos Afetados

- [StoreStoreRequest.php](file:///c:/laragon/www/maiscapinhas-erp-api/app/Http/Requests/Admin/StoreStoreRequest.php) - Criar loja
- [UpdateStoreRequest.php](file:///c:/laragon/www/maiscapinhas-erp-api/app/Http/Requests/Admin/UpdateStoreRequest.php) - Atualizar loja
- [StoreController.php](file:///c:/laragon/www/maiscapinhas-erp-api/app/Http/Controllers/Api/V1/Admin/StoreController.php) - store() e update()

---

## 3. Sugestão de Validação a Implementar

### User - Campos Faltando

```php
// StoreUserRequest e UpdateUserRequest
'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
'hire_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
'whatsapp' => ['sometimes', 'nullable', 'string', 'max:20'],
'instagram' => ['sometimes', 'nullable', 'string', 'max:50'],
'cpf' => ['sometimes', 'nullable', 'string', 'max:14', 'unique:users,cpf'],
'pix_key' => ['sometimes', 'nullable', 'string', 'max:255'],
```

### Store - Campos Faltando

```php
// StoreStoreRequest e UpdateStoreRequest
'codigo' => ['sometimes', 'nullable', 'string', 'max:20'],
'address' => ['sometimes', 'nullable', 'string', 'max:255'],
'neighborhood' => ['sometimes', 'nullable', 'string', 'max:100'],
'state' => ['sometimes', 'nullable', 'string', 'max:2'],
'zip_code' => ['sometimes', 'nullable', 'string', 'max:10'],
'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
'whatsapp' => ['sometimes', 'nullable', 'string', 'max:20'],
'instagram' => ['sometimes', 'nullable', 'string', 'max:50'],
'opening_hours' => ['sometimes', 'nullable', 'array'],
'cnpj' => ['sometimes', 'nullable', 'string', 'max:18'],
'troco_padrao' => ['sometimes', 'nullable', 'numeric', 'min:0'],
```

---

## 4. Próximos Passos

1. **Atualizar StoreUserRequest.php** - Adicionar campos faltantes
2. **Atualizar UpdateUserRequest.php** - Adicionar campos faltantes
3. **Atualizar UserController.php** - Modificar store() e update() para usar todos os campos
4. **Atualizar StoreStoreRequest.php** - Adicionar campos faltantes
5. **Atualizar UpdateStoreRequest.php** - Adicionar campos faltantes
6. **Atualizar StoreController.php** - Modificar store() e update() para usar todos os campos
7. **Atualizar UserResource.php** - Retornar todos os campos na resposta
8. **Atualizar StoreResource.php** - Retornar todos os campos na resposta

---

## 5. Decisão Necessária

> [!IMPORTANT]
> **Pergunta para o usuário:** Deseja que eu implemente todas as correções acima, adicionando todos os campos faltantes aos CRUDs de User e Store?
