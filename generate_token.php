<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$user = \App\Models\User::first();

if (!$user) {
    echo "No user found\n";
    exit(1);
}

$token = $user->createToken('api-access')->plainTextToken;

$content = "# 🔐 API Token para Testes

## Credenciais

| Campo | Valor |
|-------|-------|
| **Email** | {$user->email} |
| **Nome** | {$user->name} |
| **User ID** | {$user->id} |

## Bearer Token

```
{$token}
```

## Como usar

### Header de Autenticação

```http
Authorization: Bearer {$token}
Accept: application/json
Content-Type: application/json
```

### Exemplo cURL

```bash
curl https://api.maiscapinhas.com.br/api/v1/me \\
  -H \"Authorization: Bearer {$token}\" \\
  -H \"Accept: application/json\"
```

---
**Gerado em:** " . date('Y-m-d H:i:s') . "
";

file_put_contents('token.md', $content);

echo "=== TOKEN SALVO EM token.md ===\n";
echo "User: " . $user->email . "\n";
echo "Token: " . $token . "\n";

